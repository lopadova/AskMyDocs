import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ConversationNavigation } from './ConversationNavigation';

vi.mock('./ConversationList', () => ({
    ConversationList: ({ onSelect, onNewAnonymous }: { onSelect: (id: number) => void; onNewAnonymous: () => void }) => (
        <nav><button onClick={() => onSelect(42)}>Existing chat</button><button onClick={onNewAnonymous}>Anonymous</button></nav>
    ),
}));

describe('ConversationNavigation', () => {
    it('opens history and closes the drawer when a conversation is selected', () => {
        const onSelect = vi.fn();
        render(<ConversationNavigation projectKey={null} onSelect={onSelect} onNewAnonymous={vi.fn()}>{(toggle) => <header>{toggle}</header>}</ConversationNavigation>);
        fireEvent.click(screen.getByRole('button', { name: 'Show conversations' }));
        fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Existing chat' }));
        expect(onSelect).toHaveBeenCalledWith(42);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('supports keyboard opening, Escape and focus restoration', async () => {
        const user = userEvent.setup();
        render(<ConversationNavigation projectKey={null} onSelect={vi.fn()} onNewAnonymous={vi.fn()}>{(toggle) => <header>{toggle}</header>}</ConversationNavigation>);
        const toggle = screen.getByRole('button', { name: 'Show conversations' });
        toggle.focus();
        await user.keyboard('{Enter}');
        expect(screen.getByRole('dialog')).toContainElement(document.activeElement as HTMLElement);
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(toggle).toHaveFocus();
    });
});
