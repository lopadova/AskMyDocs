import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { Conversation } from '../chat.api';
import type { ChatFolder } from '../chat-folders.api';
import { SessionRow } from './SessionRow';

function conversation(overrides: Partial<Conversation> = {}): Conversation {
    return {
        id: 7,
        title: 'Ordini Q3',
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

const folders: ChatFolder[] = [
    { id: 1, name: 'Issue 42', position: 0, created_at: '', updated_at: '' },
    { id: 2, name: 'Refactor auth', position: 1, created_at: '', updated_at: '' },
];

function renderRow(overrides: Partial<Conversation> = {}, active = false) {
    const handlers = {
        onSelect: vi.fn(),
        onPinnedChange: vi.fn(),
        onArchivedChange: vi.fn(),
        onImportanceChange: vi.fn(),
        onFolderChange: vi.fn(),
        onRename: vi.fn(),
        onDelete: vi.fn(),
    };
    const c = conversation(overrides);
    render(<SessionRow conversation={c} active={active} folders={folders} {...handlers} />);

    return { handlers, conversation: c };
}

describe('SessionRow', () => {
    it('exposes the organisation state as assertable attributes, not just colour', () => {
        renderRow({ pinned_at: '2026-09-16T09:00:00Z', importance: 'critical' }, true);

        const row = screen.getByTestId('chat-sessions-row-7');
        expect(row).toHaveAttribute('data-pinned', 'true');
        expect(row).toHaveAttribute('data-importance', 'critical');
        expect(row).toHaveAttribute('data-active', 'true');
    });

    it('shows an importance badge only above normal', () => {
        const { unmount } = render(
            <SessionRow
                conversation={conversation({ importance: 'normal' })}
                active={false}
                folders={folders}
                onSelect={vi.fn()}
                onPinnedChange={vi.fn()}
                onArchivedChange={vi.fn()}
                onImportanceChange={vi.fn()}
                onFolderChange={vi.fn()}
                onRename={vi.fn()}
                onDelete={vi.fn()}
            />,
        );
        expect(screen.queryByText('Normal')).not.toBeInTheDocument();
        unmount();

        renderRow({ importance: 'high' });
        expect(screen.getByText('High')).toBeInTheDocument();
    });

    it('selects the session when the row body is clicked', async () => {
        const { handlers } = renderRow();

        await userEvent.click(screen.getByTestId('chat-sessions-row-7-open'));

        expect(handlers.onSelect).toHaveBeenCalledWith(7);
    });

    it('keeps the actions menu OUTSIDE the select button so it stays reachable', async () => {
        // Nesting a button inside a button is invalid HTML and makes the
        // menu unreachable by keyboard; the trigger must be a sibling.
        renderRow();
        const selectButton = screen.getByTestId('chat-sessions-row-7-open');
        const trigger = screen.getByTestId('chat-sessions-row-7-menu');

        expect(selectButton.contains(trigger)).toBe(false);
        expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');

        await userEvent.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
    });

    it('pins an unpinned session and unpins a pinned one from the same item', async () => {
        const { handlers } = renderRow();
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));
        expect(screen.getByTestId('chat-sessions-row-7-pin')).toHaveTextContent('Pin to top');

        await userEvent.click(screen.getByTestId('chat-sessions-row-7-pin'));
        expect(handlers.onPinnedChange).toHaveBeenCalledWith(7, true);
    });

    it('offers Unpin when already pinned', async () => {
        const { handlers } = renderRow({ pinned_at: '2026-09-16T09:00:00Z' });
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));

        expect(screen.getByTestId('chat-sessions-row-7-pin')).toHaveTextContent('Unpin');
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-pin'));

        expect(handlers.onPinnedChange).toHaveBeenCalledWith(7, false);
    });

    it('announces the current importance with aria-checked', async () => {
        const { handlers } = renderRow({ importance: 'high' });
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));

        expect(screen.getByTestId('chat-sessions-row-7-importance-high')).toHaveAttribute(
            'aria-checked',
            'true',
        );
        expect(screen.getByTestId('chat-sessions-row-7-importance-normal')).toHaveAttribute(
            'aria-checked',
            'false',
        );

        await userEvent.click(screen.getByTestId('chat-sessions-row-7-importance-critical'));
        expect(handlers.onImportanceChange).toHaveBeenCalledWith(7, 'critical');
    });

    it('files into a folder and unfiles through "No folder"', async () => {
        const { handlers } = renderRow({ chat_folder_id: 1 });
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));

        expect(screen.getByTestId('chat-sessions-row-7-move-1')).toHaveAttribute('aria-checked', 'true');

        await userEvent.click(screen.getByTestId('chat-sessions-row-7-move-2'));
        expect(handlers.onFolderChange).toHaveBeenCalledWith(7, 2);
    });

    it('unfiles with an explicit null rather than omitting the folder', async () => {
        const { handlers } = renderRow({ chat_folder_id: 1 });
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));

        await userEvent.click(screen.getByTestId('chat-sessions-row-7-move-none'));

        expect(handlers.onFolderChange).toHaveBeenCalledWith(7, null);
    });

    it('archives an active session and restores an archived one', async () => {
        const { handlers } = renderRow();
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));
        expect(screen.getByTestId('chat-sessions-row-7-archive')).toHaveTextContent('Archive');
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-archive'));
        expect(handlers.onArchivedChange).toHaveBeenCalledWith(7, true);
    });

    it('offers Restore when archived', async () => {
        const { handlers } = renderRow({ archived_at: '2026-09-16T09:00:00Z' });
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));

        expect(screen.getByTestId('chat-sessions-row-7-archive')).toHaveTextContent('Restore');
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-archive'));

        expect(handlers.onArchivedChange).toHaveBeenCalledWith(7, false);
    });

    it('does NOT delete on the first click — it asks first', async () => {
        const { handlers } = renderRow();
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));

        await userEvent.click(screen.getByTestId('chat-sessions-row-7-delete'));
        expect(handlers.onDelete).not.toHaveBeenCalled();

        await userEvent.click(screen.getByTestId('chat-sessions-row-7-delete-confirm'));
        expect(handlers.onDelete).toHaveBeenCalledWith(7);
    });

    it('abandons the delete when the confirm is cancelled', async () => {
        const { handlers } = renderRow();
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-delete'));

        await userEvent.click(screen.getByTestId('chat-sessions-row-7-delete-cancel'));

        expect(handlers.onDelete).not.toHaveBeenCalled();
        expect(screen.getByTestId('chat-sessions-row-7-delete')).toBeInTheDocument();
    });

    it('closes the menu on Escape', async () => {
        renderRow();
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));
        expect(screen.getByTestId('chat-sessions-row-7-menu-panel')).toBeInTheDocument();

        await userEvent.keyboard('{Escape}');

        expect(screen.queryByTestId('chat-sessions-row-7-menu-panel')).not.toBeInTheDocument();
    });

    it('renames through the callback rather than inline', async () => {
        const { handlers, conversation: c } = renderRow();
        await userEvent.click(screen.getByTestId('chat-sessions-row-7-menu'));

        await userEvent.click(screen.getByTestId('chat-sessions-row-7-rename'));

        expect(handlers.onRename).toHaveBeenCalledWith(c);
    });

    it('falls back to a readable title when the session is untitled', () => {
        renderRow({ title: null });

        expect(screen.getByTestId('chat-sessions-row-7-open')).toHaveTextContent('Untitled chat');
    });
});
