import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Team } from '../../lib/team-store';
import { Topbar } from './Topbar';

vi.mock('./TeamSwitcher', () => ({
    TeamSwitcher: ({ team }: { team: Team }) => <button type="button">{team.name}</button>,
}));
vi.mock('./UserMenu', () => ({
    UserMenu: () => <button type="button">Account</button>,
}));
vi.mock('../../features/notifications/NotificationBell', () => ({
    NotificationBell: () => <button type="button">Notifications</button>,
}));

const team: Team = {
    tenant_id: 'date',
    hash: 'team-hash',
    name: 'Date',
    projects: [],
};

describe('Topbar', () => {
    it('offers a labelled desktop control to close and reopen the sidebar', () => {
        const onToggleSidebar = vi.fn();
        const props = { team, teams: [team], onTeamChange: vi.fn(), theme: 'dark' as const, setTheme: vi.fn(), onToggleTweaks: vi.fn(), onToggleSidebar };
        const { rerender } = render(<Topbar {...props} sidebarCollapsed={false} />);
        const collapse = screen.getByRole('button', { name: 'Collapse sidebar' });
        expect(collapse).toHaveAttribute('aria-expanded', 'true');
        fireEvent.click(collapse);
        expect(onToggleSidebar).toHaveBeenCalledOnce();
        rerender(<Topbar {...props} sidebarCollapsed />);
        const expand = screen.getByRole('button', { name: 'Expand sidebar' });
        expect(expand).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(expand);
        expect(onToggleSidebar).toHaveBeenCalledTimes(2);
    });
    it('separates context, health, tools and identity into stable groups', () => {
        const setTheme = vi.fn();
        const onToggleTweaks = vi.fn();

        render(
            <Topbar
                team={team}
                teams={[team]}
                onTeamChange={vi.fn()}
                theme="dark"
                setTheme={setTheme}
                onToggleTweaks={onToggleTweaks}
                crumbs={['Chat']}
            />,
        );

        expect(screen.getByTestId('app-topbar')).toHaveClass('app-topbar');
        expect(screen.getByRole('navigation', { name: 'Breadcrumb' })).toHaveTextContent('Chat');
        expect(screen.queryByRole('status', { name: 'All systems operational' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Notifications' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Toggle theme' }));
        fireEvent.click(screen.getByRole('button', { name: 'Interface settings' }));
        expect(setTheme).toHaveBeenCalledWith('light');
        expect(onToggleTweaks).toHaveBeenCalledOnce();
    });

    it('keeps global tools available without tenant context', () => {
        render(
            <Topbar
                team={null}
                teams={[]}
                onTeamChange={vi.fn()}
                theme="light"
                setTheme={vi.fn()}
                onToggleTweaks={vi.fn()}
            />,
        );

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Notifications' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Toggle theme' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Account' })).toBeInTheDocument();
    });
});
