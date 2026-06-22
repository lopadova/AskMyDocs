import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError, AxiosHeaders } from 'axios';
import { RegisterPage } from './RegisterPage';
import { useAuthStore } from '../../lib/auth-store';

vi.mock('./auth.api', () => ({
    register: vi.fn(async () => ({ user: { id: 9, name: 'Jane', email: 'jane@acme.io' } })),
    me: vi.fn(async () => ({
        user: { id: 9, name: 'Jane', email: 'jane@acme.io' },
        roles: ['editor'],
        permissions: [],
        projects: [{ project_key: 'docs', role: 'member', scope: [] }],
    })),
}));

beforeEach(() => {
    useAuthStore.getState().clear();
    vi.clearAllMocks();
});

async function fillValid(user: ReturnType<typeof userEvent.setup>) {
    await user.type(screen.getByLabelText(/invite code/i), 'WELC0ME5');
    await user.type(screen.getByLabelText(/^name$/i), 'Jane Invitee');
    await user.type(screen.getByLabelText(/email/i), 'jane@acme.io');
    await user.type(screen.getByLabelText(/^password$/i), 'Sup3r-secret!');
    await user.type(screen.getByLabelText(/confirm password/i), 'Sup3r-secret!');
}

describe('RegisterPage', () => {
    it('renders the invite-code field as a required constraint plus the account fields', () => {
        render(<RegisterPage />);
        expect(screen.getByLabelText(/invite code/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/^name$/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /create account/i })).toBeInTheDocument();
    });

    it('requires the invite code on an empty submit', async () => {
        const user = userEvent.setup();
        render(<RegisterPage />);
        await user.click(screen.getByRole('button', { name: /create account/i }));
        expect(await screen.findByText(/invite code is required/i)).toBeInTheDocument();
    });

    it('registers, calls register + me, populates the store', async () => {
        const user = userEvent.setup();
        const onSuccess = vi.fn();
        render(<RegisterPage onSuccess={onSuccess} />);

        await fillValid(user);
        await user.click(screen.getByRole('button', { name: /create account/i }));

        const { register, me } = await import('./auth.api');
        expect(register).toHaveBeenCalledWith(
            expect.objectContaining({ email: 'jane@acme.io', code: 'WELC0ME5' }),
        );
        await vi.waitFor(() => {
            expect(me).toHaveBeenCalled();
            expect(onSuccess).toHaveBeenCalled();
        });
        expect(useAuthStore.getState().user?.email).toBe('jane@acme.io');
    });

    it('surfaces a server invite-code error next to the code field', async () => {
        const { register } = await import('./auth.api');
        const headers = new AxiosHeaders();
        const err = new AxiosError('Conflict', '409', undefined, undefined, {
            status: 409,
            statusText: 'Conflict',
            headers,
            config: { headers },
            data: { message: 'This invite code has no remaining uses.', errors: { code: ['This invite code has no remaining uses.'] } },
        });
        vi.mocked(register).mockRejectedValueOnce(err);

        const user = userEvent.setup();
        render(<RegisterPage />);
        await fillValid(user);
        await user.click(screen.getByRole('button', { name: /create account/i }));

        expect(await screen.findByText(/no remaining uses/i)).toBeInTheDocument();
    });
});
