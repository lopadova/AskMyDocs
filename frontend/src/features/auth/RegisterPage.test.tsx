import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError } from 'axios';
import { RegisterPage } from './RegisterPage';
import { useAuthStore } from '../../lib/auth-store';

vi.mock('./auth.api', () => ({
    register: vi.fn(async () => ({ user: { id: 7, name: 'Mara', email: 'mara@acme.io' } })),
    me: vi.fn(async () => ({
        user: { id: 7, name: 'Mara', email: 'mara@acme.io' },
        roles: ['viewer'],
        permissions: [],
        projects: [],
    })),
}));

beforeEach(() => {
    useAuthStore.getState().clear();
    vi.clearAllMocks();
});

async function fillValidForm(user: ReturnType<typeof userEvent.setup>) {
    await user.type(screen.getByLabelText(/^name$/i), 'Mara');
    await user.type(screen.getByLabelText(/email/i), 'mara@acme.io');
    await user.type(screen.getByLabelText(/^password$/i), 'super-secret-pw');
    await user.type(screen.getByLabelText(/confirm password/i), 'super-secret-pw');
    await user.type(screen.getByLabelText(/invite code/i), 'ABCD1234');
}

describe('RegisterPage', () => {
    it('renders every sign-up field and the submit button', () => {
        render(<RegisterPage />);
        expect(screen.getByLabelText(/^name$/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/^password$/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/confirm password/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/invite code/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /create account/i })).toBeInTheDocument();
    });

    it('blocks an empty submit with client-side validation (invite code required)', async () => {
        const user = userEvent.setup();
        const { register } = await import('./auth.api');
        render(<RegisterPage />);

        await user.click(screen.getByRole('button', { name: /create account/i }));

        expect(await screen.findByText(/an invite code is required/i)).toBeInTheDocument();
        expect(register).not.toHaveBeenCalled();
    });

    it('blocks submit when the password confirmation does not match', async () => {
        const user = userEvent.setup();
        const { register } = await import('./auth.api');
        render(<RegisterPage />);

        await user.type(screen.getByLabelText(/^name$/i), 'Mara');
        await user.type(screen.getByLabelText(/email/i), 'mara@acme.io');
        await user.type(screen.getByLabelText(/^password$/i), 'super-secret-pw');
        await user.type(screen.getByLabelText(/confirm password/i), 'different-pw');
        await user.type(screen.getByLabelText(/invite code/i), 'ABCD1234');

        await user.click(screen.getByRole('button', { name: /create account/i }));

        expect(await screen.findByText(/passwords do not match/i)).toBeInTheDocument();
        expect(register).not.toHaveBeenCalled();
    });

    it('submits, calls register + me, and populates the store', async () => {
        const user = userEvent.setup();
        const onSuccess = vi.fn();
        render(<RegisterPage onSuccess={onSuccess} />);

        await fillValidForm(user);
        await user.click(screen.getByRole('button', { name: /create account/i }));

        const { register, me } = await import('./auth.api');
        expect(register).toHaveBeenCalledWith({
            name: 'Mara',
            email: 'mara@acme.io',
            password: 'super-secret-pw',
            password_confirmation: 'super-secret-pw',
            invite_code: 'ABCD1234',
        });
        await vi.waitFor(() => {
            expect(me).toHaveBeenCalled();
            expect(onSuccess).toHaveBeenCalled();
        });
        expect(useAuthStore.getState().user?.email).toBe('mara@acme.io');
    });

    it('surfaces a server 422 invite-code error in the DOM', async () => {
        const user = userEvent.setup();
        const { register } = await import('./auth.api');
        vi.mocked(register).mockRejectedValueOnce(
            new AxiosError('Unprocessable', 'ERR_BAD_REQUEST', undefined, undefined, {
                status: 422,
                data: { errors: { invite_code: ['Invalid invite code.'] } },
            } as never),
        );
        const onSuccess = vi.fn();
        render(<RegisterPage onSuccess={onSuccess} />);

        await fillValidForm(user);
        await user.click(screen.getByRole('button', { name: /create account/i }));

        expect(await screen.findByTestId('invite_code-error')).toHaveTextContent(/invalid invite code/i);
        expect(onSuccess).not.toHaveBeenCalled();
    });
});
