import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useTeamStore } from '../../lib/team-store';
import { StandaloneShell } from './StandaloneShell';

const navigate = vi.fn();

vi.mock('@tanstack/react-router', () => ({
    useNavigate: () => navigate,
    Outlet: () => <div data-testid="outlet" />,
}));

function team(tenantId: string, hash: string, name: string) {
    return { tenant_id: tenantId, hash, name, projects: [] };
}

beforeEach(() => {
    navigate.mockClear();
    window.history.pushState({}, '', '/app/h-acme/sessions/12');
    useTeamStore.setState({
        teams: [team('acme', 'h-acme', 'Acme'), team('globex', 'h-globex', 'Globex')],
        currentTeam: 'acme',
        userId: 1,
    });
});

afterEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('StandaloneShell', () => {
    it('renders none of the app frame', () => {
        render(<StandaloneShell><div data-testid="page" /></StandaloneShell>);

        // The session rail is the only sidebar on these surfaces, so the
        // primary navigation must be absent — not hidden, absent.
        expect(screen.queryByTestId('appshell-root')).not.toBeInTheDocument();
        expect(screen.queryByTestId('app-topbar')).not.toBeInTheDocument();
        expect(screen.queryByRole('navigation', { name: /breadcrumb/i })).not.toBeInTheDocument();
        expect(screen.getByTestId('standalone-shell')).toBeInTheDocument();
        expect(screen.getByTestId('page')).toBeInTheDocument();
    });

    it('offers a way back, so the view is not a trap', async () => {
        render(<StandaloneShell />);

        await userEvent.click(screen.getByTestId('standalone-back'));

        expect(navigate).toHaveBeenCalledWith({ to: '/app' });
    });

    it('keeps the team switcher, because the tenant scopes everything below it', async () => {
        render(<StandaloneShell />);

        await userEvent.click(screen.getByTestId('team-switcher-trigger'));
        await userEvent.click(screen.getByTestId('team-switcher-item-globex'));

        // Swaps the hash IN PLACE: a team switch must not eject the user
        // back into the app frame they deliberately left.
        expect(navigate).toHaveBeenCalledWith({ to: '/app/h-globex/sessions/12' });
    });

    it('toggles the theme', async () => {
        render(<StandaloneShell />);

        await userEvent.click(screen.getByTestId('standalone-theme'));

        expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    });

    it('renders the outlet when no children are given', () => {
        render(<StandaloneShell />);

        expect(screen.getByTestId('outlet')).toBeInTheDocument();
    });
});
