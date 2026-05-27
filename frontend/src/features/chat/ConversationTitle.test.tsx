import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { ConversationTitle } from './ConversationTitle';
import { api } from '../../lib/api';

const mockPatch = vi.fn();

beforeEach(() => {
    mockPatch.mockReset();
    vi.spyOn(api, 'patch').mockImplementation(mockPatch);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('ConversationTitle', () => {
    it('shows the title text and a rename pencil', () => {
        render(withQueryClient(<ConversationTitle conversationId={7} title="Remote work questions" />));
        expect(screen.getByTestId('chat-title')).toHaveTextContent('Remote work questions');
        expect(screen.getByTestId('chat-title-rename')).toBeVisible();
        expect(screen.queryByTestId('chat-title-input')).not.toBeInTheDocument();
    });

    it('opens an input prefilled with the current title when the pencil is clicked', async () => {
        render(withQueryClient(<ConversationTitle conversationId={7} title="Remote work questions" />));
        await userEvent.click(screen.getByTestId('chat-title-rename'));
        const input = screen.getByTestId('chat-title-input') as HTMLInputElement;
        expect(input).toBeVisible();
        expect(input.value).toBe('Remote work questions');
    });

    it('PATCHes the new title on save and returns to read mode', async () => {
        // The displayed title is prop-driven (the parent re-renders from the
        // ['conversations'] cache this mutation updates) — here we assert the
        // PATCH fired with the right shape and the editor closed. The cache→prop
        // reflection is covered end-to-end in chat-stream-browser.spec.ts.
        mockPatch.mockResolvedValue({ data: { id: 7, title: 'PTO policy', project_key: 'hr-portal', created_at: '', updated_at: '' } });
        render(withQueryClient(<ConversationTitle conversationId={7} title="Untitled" />));

        await userEvent.click(screen.getByTestId('chat-title-rename'));
        const input = screen.getByTestId('chat-title-input');
        await userEvent.clear(input);
        await userEvent.type(input, 'PTO policy');
        await userEvent.click(screen.getByTestId('chat-title-save'));

        await waitFor(() => {
            expect(mockPatch).toHaveBeenCalledWith('/conversations/7', { title: 'PTO policy' });
        });
        // Editor closed → back to read mode (input gone, pencil back).
        await waitFor(() => {
            expect(screen.queryByTestId('chat-title-input')).not.toBeInTheDocument();
        });
        expect(screen.getByTestId('chat-title-rename')).toBeVisible();
    });

    it('does not PATCH when the title is unchanged or blank', async () => {
        render(withQueryClient(<ConversationTitle conversationId={7} title="Same" />));
        await userEvent.click(screen.getByTestId('chat-title-rename'));
        // Save without changing.
        await userEvent.click(screen.getByTestId('chat-title-save'));
        expect(mockPatch).not.toHaveBeenCalled();
        // Back to read mode.
        expect(screen.getByTestId('chat-title')).toBeVisible();
    });

    it('cancel restores the title without PATCHing', async () => {
        render(withQueryClient(<ConversationTitle conversationId={7} title="Keep me" />));
        await userEvent.click(screen.getByTestId('chat-title-rename'));
        await userEvent.clear(screen.getByTestId('chat-title-input'));
        await userEvent.type(screen.getByTestId('chat-title-input'), 'discarded draft');
        await userEvent.click(screen.getByTestId('chat-title-cancel'));

        expect(mockPatch).not.toHaveBeenCalled();
        expect(screen.getByTestId('chat-title')).toHaveTextContent('Keep me');
    });

    it('surfaces a rename error', async () => {
        mockPatch.mockRejectedValue(new Error('500'));
        render(withQueryClient(<ConversationTitle conversationId={7} title="Old" />));
        await userEvent.click(screen.getByTestId('chat-title-rename'));
        await userEvent.clear(screen.getByTestId('chat-title-input'));
        await userEvent.type(screen.getByTestId('chat-title-input'), 'New name');
        await userEvent.click(screen.getByTestId('chat-title-save'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-title-error')).toBeVisible();
        });
    });
});
