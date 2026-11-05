import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useAuthStore } from '../../lib/auth-store';
import { useTeamStore } from '../../lib/team-store';
import { CommandPalette } from './CommandPalette';

const { navigate } = vi.hoisted(() => ({ navigate: vi.fn() }));
vi.mock('@tanstack/react-router', () => ({
    useNavigate: () => navigate,
    Link: ({ to, children, ...props }: { to: string; children: React.ReactNode }) => <a href={to} {...props}>{children}</a>,
}));
beforeEach(() => {
    useAuthStore.setState({ roles: ['admin'], permissions: [], features: {}, user: null });
    useTeamStore.setState({ currentTeam: 'tenant', teams: [{ tenant_id: 'tenant', hash: 'team-hash', name: 'Team', projects: [] }] });
    navigate.mockClear();
});
describe('CommandPalette', () => {
    it('opens with the keyboard, focuses search, and clears a closed query', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);
        await user.keyboard('{Control>}k{/Control}');
        const input = await screen.findByRole('combobox', { name: 'Search pages and tools' });
        expect(input).toHaveFocus();
        await user.type(input, 'collections');
        await user.keyboard('{Escape}');
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        await user.keyboard('{Control>}k{/Control}');
        expect(screen.getByRole('combobox')).toHaveValue('');
    });
    it('finds real destinations and opens the selected result with Enter', async () => {
        const user = userEvent.setup();
        render(<CommandPalette />);
        act(() => window.dispatchEvent(new CustomEvent('amd:palette')));
        await user.type(await screen.findByRole('combobox'), 'collections');
        expect(screen.getAllByRole('option')).toHaveLength(1);
        expect(screen.getByRole('option', { name: /Collections/ })).toHaveAttribute('href', '/app/team-hash/admin/collections');
        await user.keyboard('{Enter}');
        expect(navigate).toHaveBeenCalledWith({ to: '/app/team-hash/admin/collections' });
        expect(screen.queryByText(/5 new/)).not.toBeInTheDocument();
    });
    it('does not reveal inaccessible or disabled destinations', async () => {
        useAuthStore.setState({ roles: ['viewer'] });
        const user = userEvent.setup();
        render(<CommandPalette />);
        act(() => window.dispatchEvent(new CustomEvent('amd:palette')));
        await user.type(await screen.findByRole('combobox'), 'users');
        expect(screen.queryByRole('option')).not.toBeInTheDocument();
        expect(screen.getByText('No matching pages.')).toBeInTheDocument();
    });
});
