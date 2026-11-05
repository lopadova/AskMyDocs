import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatActivityBadge } from './ChatActivityBadge';
import { clearAgentChatSessions, getAgentChatSession } from './agent-chat-session';
import { useTeamStore } from '../../lib/team-store';

beforeEach(() => {
    clearAgentChatSessions();
    useTeamStore.setState({ userId: null, currentTeam: null });
});
afterEach(() => {
    clearAgentChatSessions();
    vi.useRealTimers();
});

describe('ChatActivityBadge', () => {
    it('shows progress, then the elapsed whole minutes from the reply, until the session is opened', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-21T12:00:00Z'));
        const session = getAgentChatSession(7, null, null);
        session.store.setState({ status: 'streaming' });
        const { rerender } = render(<ChatActivityBadge conversationId={7} active={false} />);
        expect(screen.getByTestId('chat-activity-7')).toHaveAttribute('data-state', 'running');
        expect(screen.getByLabelText('Search continues in the background')).toBeInTheDocument();

        act(() => session.store.setState({ status: 'ready', unreadAt: Date.now() }));
        expect(screen.getByTestId('chat-activity-7')).toHaveTextContent('0 min');
        act(() => vi.advanceTimersByTime(180_000));
        expect(screen.getByTestId('chat-activity-7')).toHaveTextContent('3 min');
        expect(screen.getByLabelText('Unread reply received 3 minutes ago')).toBeInTheDocument();
        act(() => { const detach = session.attach(); detach(); });
        rerender(<ChatActivityBadge conversationId={7} active={false} />);
        expect(screen.queryByTestId('chat-activity-7')).not.toBeInTheDocument();
    });

    it('shows confirmation and failure states without presenting them as unread replies', () => {
        const session = getAgentChatSession(7, null, null);
        const { rerender } = render(<ChatActivityBadge conversationId={7} active={false} />);
        expect(screen.queryByTestId('chat-activity-7')).not.toBeInTheDocument();
        act(() => session.store.setState({ confirmation: { physicalExtension: 25, logicalExtension: 0 } }));
        expect(screen.getByTestId('chat-activity-7')).toHaveTextContent('Confirmation needed');
        act(() => session.store.setState({ confirmation: null, status: 'error' }));
        expect(screen.getByTestId('chat-activity-7')).toHaveTextContent('Search failed');
        rerender(<ChatActivityBadge conversationId={7} active />);
        expect(screen.getByTestId('chat-activity-7')).toHaveAttribute('data-state', 'error');
    });

    it('translates the background and unread descriptions into Italian', () => {
        localStorage.setItem('amd-ui-locale', 'it');
        const session = getAgentChatSession(7, null, null);
        session.store.setState({ status: 'streaming' });
        render(<ChatActivityBadge conversationId={7} active={false} />);
        expect(screen.getByLabelText('La ricerca prosegue in background')).toBeInTheDocument();
        act(() => session.store.setState({ status: 'ready', unreadAt: Date.now() }));
        expect(screen.getByLabelText('Risposta da leggere ricevuta 0 minuti fa')).toBeInTheDocument();
    });
});
