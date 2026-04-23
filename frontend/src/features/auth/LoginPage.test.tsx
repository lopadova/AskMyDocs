import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LoginPage } from './LoginPage';
import { useAuthStore } from '../../lib/auth-store';

vi.mock('./auth.api', () => ({
    login: vi.fn(async () => ({ user: { id: 1, name: 'Elena', email: 'elena@acme.io' } })),
    me: vi.fn(async () => ({
        user: { id: 1, name: 'Elena', email: 'elena@acme.io' },
        roles: ['super-admin'],
        permissions: [],
        projects: [],
    })),
}));

beforeEach(() => {
    useAuthStore.getState().clear();
});

describe('LoginPage', () => {
    it('renders email + password fields and the submit button', () => {
        render(<LoginPage />);
        expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/^password$/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument();
    });

    it('shows client-side validation for empty submit', async () => {
        const user = userEvent.setup();
        render(<LoginPage />);
        await user.click(screen.getByRole('button', { name: /sign in/i }));
        expect(await screen.findByText(/valid email address/i)).toBeInTheDocument();
    });

    it('submits credentials, calls login + me, populates the store', async () => {
        const user = userEvent.setup();
        const onSuccess = vi.fn();
        render(<LoginPage onSuccess={onSuccess} />);

        await user.type(screen.getByLabelText(/email/i), 'elena@acme.io');
        await user.type(screen.getByLabelText(/^password$/i), 'correct-horse-battery-staple');
        await user.click(screen.getByRole('button', { name: /sign in/i }));

        const { login, me } = await import('./auth.api');
        expect(login).toHaveBeenCalledWith('elena@acme.io', 'correct-horse-battery-staple', false);
        await vi.waitFor(() => {
            expect(me).toHaveBeenCalled();
            expect(onSuccess).toHaveBeenCalled();
        });
        expect(useAuthStore.getState().user?.email).toBe('elena@acme.io');
    });
});
