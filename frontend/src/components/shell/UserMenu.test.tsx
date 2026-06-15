import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { UserMenu } from './UserMenu';
import { useAuthStore } from '../../lib/auth-store';
import { queryClient } from '../../lib/query-client';

const navigateSpy = vi.fn();

vi.mock('@tanstack/react-router', () => ({
    useNavigate: () => navigateSpy,
}));

vi.mock('../../features/auth/auth.api', () => ({
    logout: vi.fn(),
}));

// Imported AFTER the mock so we get the mocked reference.
import { logout } from '../../features/auth/auth.api';

const USER = { id: 7, name: 'Elena Rossi', email: 'elena@acme.io' };

beforeEach(() => {
    useAuthStore.setState({ user: USER, roles: [], permissions: [], projects: [], loading: false });
    vi.mocked(logout).mockReset();
    navigateSpy.mockReset();
});

afterEach(() => {
    vi.restoreAllMocks();
    useAuthStore.getState().clear();
});

describe('UserMenu', () => {
    it('shows the signed-in user on the trigger and opens the account menu', async () => {
        const user = userEvent.setup();
        render(<UserMenu />);

        const trigger = screen.getByTestId('user-menu-trigger');
        expect(trigger).toHaveTextContent('Elena Rossi');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');

        await user.click(trigger);

        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        const menu = screen.getByTestId('user-menu');
        expect(menu).toHaveAttribute('role', 'menu');
        expect(screen.getByTestId('user-menu-name')).toHaveTextContent('Elena Rossi');
        expect(screen.getByTestId('user-menu-email')).toHaveTextContent('elena@acme.io');
        expect(screen.getByTestId('user-menu-logout')).toBeInTheDocument();
    });

    it('signs out: calls logout, clears the auth store + query cache, then navigates to /login', async () => {
        const user = userEvent.setup();
        vi.mocked(logout).mockResolvedValue(undefined);
        const clearCache = vi.spyOn(queryClient, 'clear');

        render(<UserMenu />);
        await user.click(screen.getByTestId('user-menu-trigger'));
        await user.click(screen.getByTestId('user-menu-logout'));

        await vi.waitFor(() => {
            expect(logout).toHaveBeenCalledTimes(1);
            expect(navigateSpy).toHaveBeenCalledWith({ to: '/login' });
        });
        // Local session state is dropped ONLY after the server confirms.
        expect(useAuthStore.getState().user).toBeNull();
        expect(clearCache).toHaveBeenCalledTimes(1);
    });

    it('keeps the session and surfaces an error when the server sign-out fails', async () => {
        const user = userEvent.setup();
        vi.mocked(logout).mockRejectedValue(new Error('network'));
        const clearCache = vi.spyOn(queryClient, 'clear');

        render(<UserMenu />);
        await user.click(screen.getByTestId('user-menu-trigger'));
        await user.click(screen.getByTestId('user-menu-logout'));

        // R14 — the failure is shown, NOT swallowed…
        const error = await screen.findByTestId('user-menu-error');
        expect(error).toHaveAttribute('role', 'alert');
        // …and the client session is left intact because the server cookie
        // is still valid (clearing it would be a false "you're logged out").
        expect(useAuthStore.getState().user).toEqual(USER);
        expect(clearCache).not.toHaveBeenCalled();
        expect(navigateSpy).not.toHaveBeenCalled();
        // The action stays retryable.
        expect(screen.getByTestId('user-menu-logout')).toHaveTextContent('Retry sign out');
    });

    it('clears a prior sign-out error when the menu is reopened', async () => {
        const user = userEvent.setup();
        vi.mocked(logout).mockRejectedValue(new Error('network'));

        render(<UserMenu />);
        await user.click(screen.getByTestId('user-menu-trigger'));
        await user.click(screen.getByTestId('user-menu-logout'));
        expect(await screen.findByTestId('user-menu-error')).toBeInTheDocument();

        // Close (Escape) then reopen — the menu must start clean, not stuck
        // on the previous failure's "Retry sign out" / error banner.
        await user.keyboard('{Escape}');
        await user.click(screen.getByTestId('user-menu-trigger'));

        expect(screen.queryByTestId('user-menu-error')).not.toBeInTheDocument();
        expect(screen.getByTestId('user-menu-logout')).toHaveTextContent('Sign out');
        expect(screen.getByTestId('user-menu')).toHaveAttribute('data-state', 'idle');
    });

    it('closes on Escape and returns focus to the trigger', async () => {
        const user = userEvent.setup();
        render(<UserMenu />);

        const trigger = screen.getByTestId('user-menu-trigger');
        await user.click(trigger);
        expect(screen.getByTestId('user-menu')).toBeInTheDocument();

        await user.keyboard('{Escape}');
        expect(screen.queryByTestId('user-menu')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });

    it('renders nothing when there is no authenticated user', () => {
        useAuthStore.setState({ user: null });
        const { container } = render(<UserMenu />);
        expect(container).toBeEmptyDOMElement();
        expect(screen.queryByTestId('user-menu-trigger')).not.toBeInTheDocument();
    });
});
