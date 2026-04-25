import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { UserForm } from './UserForm';
import type { AdminRole } from '../admin.api';

const ROLES: AdminRole[] = [
    {
        id: 1,
        name: 'viewer',
        guard_name: 'web',
        permissions: [],
        users_count: 0,
        created_at: null,
        updated_at: null,
    },
    {
        id: 2,
        name: 'editor',
        guard_name: 'web',
        permissions: [],
        users_count: 0,
        created_at: null,
        updated_at: null,
    },
];

describe('UserForm', () => {
    it('renders zod field errors with the documented testids on submit', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();

        render(<UserForm mode="create" roles={ROLES} onSubmit={onSubmit} />);

        // Submit empty — name, email, password must all fail.
        await user.click(screen.getByTestId('user-form-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('user-form-name-error')).toBeInTheDocument();
        });
        expect(screen.getByTestId('user-form-email-error')).toBeInTheDocument();
        expect(screen.getByTestId('user-form-password-error')).toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('merges server-side field errors onto the form', () => {
        render(
            <UserForm
                mode="create"
                roles={ROLES}
                onSubmit={() => undefined}
                serverErrors={{ email: 'The email has already been taken.' }}
            />,
        );

        const err = screen.getByTestId('user-form-email-error');
        expect(err).toHaveTextContent('already been taken');
    });

    it('allows empty password in edit mode and submits successfully', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();

        render(
            <UserForm
                mode="edit"
                roles={ROLES}
                onSubmit={onSubmit}
                initial={{
                    id: 1,
                    name: 'Alice',
                    email: 'alice@demo.local',
                    email_verified_at: null,
                    is_active: true,
                    deleted_at: null,
                    created_at: null,
                    updated_at: null,
                    roles: ['viewer'],
                    permissions: [],
                }}
            />,
        );

        await user.click(screen.getByTestId('user-form-submit'));

        await waitFor(() => expect(onSubmit).toHaveBeenCalled());
        const values = onSubmit.mock.calls[0][0];
        expect(values.name).toBe('Alice');
        expect(values.email).toBe('alice@demo.local');
        expect(values.password).toBe('');
    });
});
