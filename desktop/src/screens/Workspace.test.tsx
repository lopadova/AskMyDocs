import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatScreen } from './ChatScreen';
import { KnowledgeScreen } from './KnowledgeScreen';
import { ApiError, chat, fetchKnowledgeTree } from '../lib/api';
import { loadThreads, saveThreads } from '../lib/store';

vi.mock('../lib/store', () => ({ loadThreads: vi.fn(), saveThreads: vi.fn().mockResolvedValue(undefined) }));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error { constructor(public status: number, message: string) { super(message); } },
  chat: vi.fn(), fetchKnowledgeTree: vi.fn(), fetchDocumentPreview: vi.fn().mockResolvedValue({ title: 'Decision', project_key: 'alpha', content: '# Decision' }),
}));
vi.mock('@tauri-apps/plugin-opener', () => ({ openUrl: vi.fn() }));
const props = { token: 'token', tenantId: 'tenant', projectKey: 'alpha' };
const thread = (id: string, title: string, extra = {}) => ({ id, title, createdAt: 1, messages: [], ...extra });
beforeEach(() => { vi.clearAllMocks(); vi.mocked(loadThreads).mockResolvedValue([]); });
afterEach(cleanup);

describe('Desktop sessions', () => {
  it('keeps legacy histories and scopes all persistence to the selected workspace', async () => {
    vi.mocked(loadThreads).mockResolvedValue([thread('old', 'Existing conversation')]);
    render(<ChatScreen {...props} />);
    expect(await screen.findByDisplayValue('Existing conversation')).toBeInTheDocument();
    expect(loadThreads).toHaveBeenCalledWith('tenant', 'alpha');
    fireEvent.change(screen.getByLabelText('Conversation title'), { target: { value: 'Renamed' } });
    await waitFor(() => expect(saveThreads).toHaveBeenLastCalledWith('tenant', 'alpha', [expect.objectContaining({ id: 'old', title: 'Renamed' })]));
  });
  it('pins, filters, archives and restores conversations without deleting their messages', async () => {
    vi.mocked(loadThreads).mockResolvedValue([thread('one', 'One'), thread('two', 'Two')]);
    render(<ChatScreen {...props} />);
    await screen.findByDisplayValue('One');
    fireEvent.click(screen.getByLabelText('Pin conversation'));
    expect(screen.getByLabelText('Unpin conversation')).toHaveAttribute('aria-pressed', 'true');
    fireEvent.click(screen.getByLabelText('Archive conversation'));
    expect(screen.queryByTestId('chat-thread-one-open')).not.toBeInTheDocument();
    fireEvent.click(screen.getByText('Archived', { exact: true }));
    fireEvent.click(screen.getByTestId('chat-thread-one-open'));
    fireEvent.click(screen.getByLabelText('Restore conversation'));
    fireEvent.click(screen.getByText('Recent', { exact: true }));
    expect(screen.getByTestId('chat-thread-list').firstElementChild).toHaveTextContent('One');
    fireEvent.change(screen.getByLabelText('Filter conversations'), { target: { value: 'Two' } });
    expect(screen.queryByTestId('chat-thread-one-open')).not.toBeInTheDocument();
    expect(screen.getByTestId('chat-thread-two-open')).toBeInTheDocument();
  });
  it('keeps the failed question available to retry', async () => {
    vi.mocked(chat).mockRejectedValue(new ApiError(503, 'Service temporarily unavailable', null));
    render(<ChatScreen {...props} />);
    await waitFor(() => expect(screen.getByTestId('chat-new-thread')).toBeEnabled());
    fireEvent.change(screen.getByTestId('chat-input'), { target: { value: 'What changed?' } });
    fireEvent.click(screen.getByTestId('chat-send'));
    expect(await screen.findByRole('alert')).toHaveTextContent('Service temporarily unavailable');
    expect(screen.getByTestId('chat-input')).toHaveValue('What changed?');
    expect(chat).toHaveBeenCalledWith('token', 'What changed?', 'tenant', 'alpha');
  });
  it('does not overwrite local history if reading storage fails', async () => {
    vi.mocked(loadThreads).mockRejectedValue(new Error('storage unavailable'));
    render(<ChatScreen {...props} />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load local conversations');
    expect(saveThreads).not.toHaveBeenCalled();
    expect(screen.getByTestId('chat-new-thread')).toBeDisabled();
  });
  it('requires confirmation before deleting a conversation', async () => {
    vi.mocked(loadThreads).mockResolvedValue([thread('one', 'One')]);
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<ChatScreen {...props} />);
    await screen.findByDisplayValue('One');
    fireEvent.click(screen.getByLabelText('Delete conversation'));
    expect(screen.getByTestId('chat-thread-one-open')).toBeInTheDocument();
    vi.mocked(window.confirm).mockReturnValue(true);
    fireEvent.click(screen.getByLabelText('Delete conversation'));
    expect(screen.queryByTestId('chat-thread-one-open')).not.toBeInTheDocument();
  });
});

describe('Knowledge browser', () => {
  it('loads only the selected workspace and opens source documents', async () => {
    vi.mocked(fetchKnowledgeTree).mockResolvedValue({ tree: [{ type: 'folder', name: 'Decisions', path: 'decisions', children: [{ type: 'doc', name: 'Decision.md', path: 'decisions/decision.md', meta: { id: 42, project_key: 'alpha', is_canonical: true, canonical_type: 'decision' } }] }], counts: { docs: 1, canonical: 1, trashed: 0 }, generated_at: '' });
    render(<KnowledgeScreen {...props} />);
    fireEvent.click(await screen.findByRole('button', { name: /Decision.md/ }));
    expect(await screen.findByRole('dialog')).toBeInTheDocument();
    expect(fetchKnowledgeTree).toHaveBeenCalledWith('token', 'tenant', 'alpha');
  });
  it('explains older-server compatibility and allows retry', async () => {
    vi.mocked(fetchKnowledgeTree).mockRejectedValueOnce(new ApiError(404, 'Not found', null)).mockResolvedValueOnce({ tree: [], counts: { docs: 0, canonical: 0, trashed: 0 }, generated_at: '' });
    render(<KnowledgeScreen {...props} />);
    expect(await screen.findByRole('alert')).toHaveTextContent('You can still use Search');
    fireEvent.click(screen.getByText('Try again'));
    expect(await screen.findByText('No documents yet')).toBeInTheDocument();
  });
});

it('keeps Escape and focus restoration on older WebViews without native dialogs', async () => {
  const { DocumentModal } = await import('../components/DocumentModal');
  const original = HTMLDialogElement.prototype.showModal;
  Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value: undefined });
  const onClose = vi.fn();
  const trigger = document.createElement('button'); document.body.append(trigger); trigger.focus();
  try {
    const view = render(<DocumentModal {...props} target={{ documentId: 42 }} onClose={onClose} />);
    expect(screen.getByLabelText('Close document')).toHaveFocus();
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(onClose).toHaveBeenCalledOnce();
    view.unmount();
    expect(trigger).toHaveFocus();
  } finally {
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value: original });
    trigger.remove();
  }
});
