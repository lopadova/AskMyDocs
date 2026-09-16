import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactElement } from 'react';
import { chatApi, type Conversation } from '../chat.api';
import { chatFoldersApi, type ChatFolder } from '../chat-folders.api';
import { SessionSidebar } from './SessionSidebar';

function conversation(overrides: Partial<Conversation> = {}): Conversation {
    return {
        id: 1,
        title: 'Session',
        project_key: 'engineering',
        chat_folder_id: null,
        pinned_at: null,
        archived_at: null,
        importance: 'normal',
        created_at: '2026-09-16T10:00:00Z',
        updated_at: '2026-09-16T10:00:00Z',
        ...overrides,
    };
}

const folder = (id: number, name: string): ChatFolder => ({
    id,
    name,
    position: id,
    created_at: '2026-09-16T10:00:00Z',
    updated_at: '2026-09-16T10:00:00Z',
});

function renderSidebar(ui: ReactElement) {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

const props = {
    projectKey: 'engineering',
    projectScopeValue: 'engineering',
    teamProjectKeys: ['engineering', 'hr-portal'],
    onScopeChange: vi.fn(),
    activeId: null,
    onSelect: vi.fn(),
    onOpenKnowledgeBase: vi.fn(),
};

beforeEach(() => {
    vi.spyOn(chatApi, 'listConversations').mockResolvedValue([]);
    vi.spyOn(chatFoldersApi, 'list').mockResolvedValue([]);
});

afterEach(() => vi.restoreAllMocks());

describe('SessionSidebar', () => {
    it('puts the knowledge-base entry above the sessions', async () => {
        renderSidebar(<SessionSidebar {...props} />);

        const sidebar = await screen.findByTestId('chat-sessions-sidebar');
        const kb = screen.getByTestId('chat-sessions-kb-entry');
        const search = screen.getByTestId('chat-sessions-search');

        // DOCUMENT_POSITION_FOLLOWING: the KB entry precedes the search box.
        expect(kb.compareDocumentPosition(search) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(sidebar).toBeInTheDocument();
    });

    it('navigates to the KB page through the injected callback', async () => {
        const onOpenKnowledgeBase = vi.fn();
        renderSidebar(<SessionSidebar {...props} onOpenKnowledgeBase={onOpenKnowledgeBase} />);

        await userEvent.click(await screen.findByTestId('chat-sessions-kb-entry'));

        expect(onOpenKnowledgeBase).toHaveBeenCalled();
    });

    it('reports the empty state rather than a bare ready state', async () => {
        renderSidebar(<SessionSidebar {...props} />);

        const sidebar = await screen.findByTestId('chat-sessions-sidebar');
        await waitFor(() => expect(sidebar).toHaveAttribute('data-state', 'empty'));
        expect(screen.getByTestId('chat-sessions-empty')).toBeInTheDocument();
    });

    it('surfaces a load failure in the DOM', async () => {
        vi.mocked(chatApi.listConversations).mockRejectedValue(new Error('boom'));
        renderSidebar(<SessionSidebar {...props} />);

        const error = await screen.findByTestId('chat-sessions-error');
        expect(error).toBeInTheDocument();
        expect(await screen.findByTestId('chat-sessions-sidebar')).toHaveAttribute(
            'data-state',
            'error',
        );
    });

    it('groups pinned, foldered and unfiled sessions separately', async () => {
        vi.mocked(chatApi.listConversations).mockResolvedValue([
            conversation({ id: 1, title: 'Pinned one', pinned_at: '2026-09-16T09:00:00Z' }),
            conversation({ id: 2, title: 'Filed one', chat_folder_id: 10 }),
            conversation({ id: 3, title: 'Loose one' }),
        ]);
        vi.mocked(chatFoldersApi.list).mockResolvedValue([folder(10, 'Issue 42')]);

        renderSidebar(<SessionSidebar {...props} />);

        const pinnedGroup = await screen.findByTestId('chat-sessions-group-pinned');
        expect(pinnedGroup).toHaveTextContent('Pinned one');

        const folderGroup = screen.getByTestId('chat-sessions-folder-10');
        expect(folderGroup).toHaveTextContent('Filed one');
        expect(folderGroup).toHaveAttribute('data-count', '1');

        // A pinned session appears ONLY under Pinned, never twice.
        expect(folderGroup).not.toHaveTextContent('Pinned one');
        expect(screen.getByTestId('chat-sessions-group-unfiled')).toHaveTextContent('Loose one');
        expect(screen.getAllByTestId(/^chat-sessions-row-\d+$/)).toHaveLength(3);
    });

    it('collapses a folder with aria-expanded and hides its panel', async () => {
        vi.mocked(chatApi.listConversations).mockResolvedValue([
            conversation({ id: 2, title: 'Filed one', chat_folder_id: 10 }),
        ]);
        vi.mocked(chatFoldersApi.list).mockResolvedValue([folder(10, 'Issue 42')]);
        renderSidebar(<SessionSidebar {...props} />);

        const toggle = await screen.findByTestId('chat-sessions-folder-10-toggle');
        expect(toggle).toHaveAttribute('aria-expanded', 'true');

        await userEvent.click(toggle);

        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(screen.getByTestId('chat-sessions-folder-10')).toHaveAttribute(
            'data-expanded',
            'false',
        );
    });

    it('shows an empty folder as empty instead of hiding it', async () => {
        vi.mocked(chatFoldersApi.list).mockResolvedValue([folder(10, 'Issue 42')]);
        vi.mocked(chatApi.listConversations).mockResolvedValue([conversation({ id: 3 })]);

        renderSidebar(<SessionSidebar {...props} />);

        const group = await screen.findByTestId('chat-sessions-folder-10');
        expect(group).toHaveAttribute('data-count', '0');
        expect(group).toHaveTextContent('Empty folder.');
    });

    it('does not fetch the archived list until the drawer is opened', async () => {
        vi.mocked(chatApi.listConversations).mockResolvedValue([conversation()]);
        renderSidebar(<SessionSidebar {...props} />);

        await screen.findByTestId('chat-sessions-row-1');
        expect(chatApi.listConversations).toHaveBeenCalledTimes(1);
        expect(chatApi.listConversations).not.toHaveBeenCalledWith('archived');

        await userEvent.click(screen.getByTestId('chat-sessions-archived-toggle'));

        await waitFor(() =>
            expect(chatApi.listConversations).toHaveBeenCalledWith('archived'),
        );
        expect(screen.getByTestId('chat-sessions-archived-toggle')).toHaveAttribute(
            'aria-pressed',
            'true',
        );
    });

    it('filters by title and reports no results distinctly from empty', async () => {
        vi.mocked(chatApi.listConversations).mockResolvedValue([
            conversation({ id: 1, title: 'Ordini Q3' }),
        ]);
        renderSidebar(<SessionSidebar {...props} />);

        await userEvent.type(await screen.findByTestId('chat-sessions-search'), 'nothing');

        expect(screen.getByTestId('chat-sessions-no-results')).toBeInTheDocument();
        // Still `ready`: the list is non-empty, the FILTER matched nothing.
        expect(screen.getByTestId('chat-sessions-sidebar')).toHaveAttribute('data-state', 'ready');
    });

    it('pins a session through the organisation endpoint', async () => {
        vi.mocked(chatApi.listConversations).mockResolvedValue([conversation({ id: 1 })]);
        const organize = vi
            .spyOn(chatApi, 'organizeConversation')
            .mockResolvedValue(conversation({ id: 1, pinned_at: '2026-09-16T11:00:00Z' }));

        renderSidebar(<SessionSidebar {...props} />);
        await userEvent.click(await screen.findByTestId('chat-sessions-row-1-menu'));
        await userEvent.click(screen.getByTestId('chat-sessions-row-1-pin'));

        await waitFor(() => expect(organize).toHaveBeenCalledWith(1, { pinned: true }));
    });

    it('creates a session without duplicating a row already in the cache', async () => {
        // R25: the optimistic prepend must dedupe by id, or a prior
        // refetch race renders two components with the same testid.
        const created = conversation({ id: 5, title: 'Fresh' });
        vi.mocked(chatApi.listConversations).mockResolvedValue([created]);
        vi.spyOn(chatApi, 'createConversation').mockResolvedValue(created);
        const onSelect = vi.fn();

        renderSidebar(<SessionSidebar {...props} onSelect={onSelect} />);
        await screen.findByTestId('chat-sessions-row-5');

        await userEvent.click(screen.getByTestId('chat-sessions-new'));

        await waitFor(() => expect(onSelect).toHaveBeenCalledWith(5));
        // Strict locator, no .first(): two same-id rows is a real bug.
        expect(screen.getAllByTestId('chat-sessions-row-5')).toHaveLength(1);
    });

    it('surfaces a session-creation failure in the DOM', async () => {
        vi.spyOn(chatApi, 'createConversation').mockRejectedValue(new Error('creation refused'));
        renderSidebar(<SessionSidebar {...props} />);

        await userEvent.click(await screen.findByTestId('chat-sessions-new'));

        expect(await screen.findByTestId('chat-sessions-new-error')).toHaveTextContent(
            'creation refused',
        );
    });

    it('deletes a session and clears the selection when it was the open one', async () => {
        vi.mocked(chatApi.listConversations).mockResolvedValue([conversation({ id: 1 })]);
        vi.spyOn(chatApi, 'deleteConversation').mockResolvedValue(undefined);
        const onSelect = vi.fn();

        renderSidebar(<SessionSidebar {...props} activeId={1} onSelect={onSelect} />);
        await userEvent.click(await screen.findByTestId('chat-sessions-row-1-menu'));
        await userEvent.click(screen.getByTestId('chat-sessions-row-1-delete'));
        await userEvent.click(screen.getByTestId('chat-sessions-row-1-delete-confirm'));

        await waitFor(() => expect(onSelect).toHaveBeenCalledWith(null));
    });

    it('creates a folder through the name dialog', async () => {
        const create = vi.spyOn(chatFoldersApi, 'create').mockResolvedValue(folder(11, 'Issue 7'));
        renderSidebar(<SessionSidebar {...props} />);

        await userEvent.click(await screen.findByTestId('chat-sessions-new-folder'));
        await userEvent.type(screen.getByTestId('chat-sessions-folder-dialog-input'), 'Issue 7');
        await userEvent.click(screen.getByTestId('chat-sessions-folder-dialog-submit'));

        await waitFor(() => expect(create).toHaveBeenCalledWith('Issue 7'));
    });

    it('asks before deleting a folder, and says the sessions are only unfiled', async () => {
        vi.mocked(chatFoldersApi.list).mockResolvedValue([folder(10, 'Issue 42')]);
        vi.mocked(chatApi.listConversations).mockResolvedValue([
            conversation({ id: 2, chat_folder_id: 10 }),
        ]);
        const remove = vi.spyOn(chatFoldersApi, 'remove').mockResolvedValue(undefined);
        renderSidebar(<SessionSidebar {...props} />);

        await userEvent.click(await screen.findByTestId('chat-sessions-folder-10-delete'));

        // Nothing deleted yet: the first click only asked.
        expect(remove).not.toHaveBeenCalled();
        const prompt = screen.getByTestId('chat-sessions-folder-10-delete-confirm-prompt');
        // The consequence has to be stated — a bin icon does not convey
        // "your sessions survive".
        expect(prompt).toHaveTextContent(/not deleted/i);

        await userEvent.click(screen.getByTestId('chat-sessions-folder-10-delete-confirm'));

        await waitFor(() => expect(remove).toHaveBeenCalledWith(10));
    });

    it('abandons a folder delete when the confirm is cancelled', async () => {
        vi.mocked(chatFoldersApi.list).mockResolvedValue([folder(10, 'Issue 42')]);
        const remove = vi.spyOn(chatFoldersApi, 'remove').mockResolvedValue(undefined);
        renderSidebar(<SessionSidebar {...props} />);

        await userEvent.click(await screen.findByTestId('chat-sessions-folder-10-delete'));
        await userEvent.click(screen.getByTestId('chat-sessions-folder-10-delete-cancel'));

        expect(remove).not.toHaveBeenCalled();
        expect(
            screen.queryByTestId('chat-sessions-folder-10-delete-confirm-prompt'),
        ).not.toBeInTheDocument();
    });

    it('surfaces a failed folder delete instead of leaving the row unchanged', async () => {
        vi.mocked(chatFoldersApi.list).mockResolvedValue([folder(10, 'Issue 42')]);
        vi.spyOn(chatFoldersApi, 'remove').mockRejectedValue(new Error('403'));
        renderSidebar(<SessionSidebar {...props} />);

        await userEvent.click(await screen.findByTestId('chat-sessions-folder-10-delete'));
        await userEvent.click(screen.getByTestId('chat-sessions-folder-10-delete-confirm'));

        expect(await screen.findByTestId('chat-sessions-folder-delete-error')).toBeVisible();
    });

    it('surfaces a failed session delete', async () => {
        vi.mocked(chatApi.listConversations).mockResolvedValue([conversation({ id: 1 })]);
        vi.spyOn(chatApi, 'deleteConversation').mockRejectedValue(new Error('500'));
        renderSidebar(<SessionSidebar {...props} />);

        await userEvent.click(await screen.findByTestId('chat-sessions-row-1-menu'));
        await userEvent.click(screen.getByTestId('chat-sessions-row-1-delete'));
        await userEvent.click(screen.getByTestId('chat-sessions-row-1-delete-confirm'));

        expect(await screen.findByTestId('chat-sessions-delete-error')).toBeVisible();
    });
});
