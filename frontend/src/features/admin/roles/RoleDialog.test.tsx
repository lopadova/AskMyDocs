import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RoleDialog } from './RoleDialog';
import type { AdminPermissionCatalogue, AdminRole } from '../admin.api';

// Stub axios-based hooks — RoleDialog doesn't need the network for the
// matrix-toggle tests, only for the save handler which we don't trigger
// in these cases.
vi.mock('../../../lib/api', () => ({
    api: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
    ensureCsrfCookie: vi.fn(),
    resetCsrf: vi.fn(),
}));

const CATALOGUE: AdminPermissionCatalogue = {
    data: [
        { id: 1, name: 'kb.read.any', guard_name: 'web' },
        { id: 2, name: 'kb.edit.any', guard_name: 'web' },
        { id: 3, name: 'kb.delete.any', guard_name: 'web' },
        { id: 4, name: 'users.manage', guard_name: 'web' },
    ],
    grouped: {
        kb: [
            { id: 1, name: 'kb.read.any', guard_name: 'web' },
            { id: 2, name: 'kb.edit.any', guard_name: 'web' },
            { id: 3, name: 'kb.delete.any', guard_name: 'web' },
        ],
        users: [{ id: 4, name: 'users.manage', guard_name: 'web' }],
    },
};

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

const EXISTING_ROLE: AdminRole = {
    id: 10,
    name: 'editor',
    guard_name: 'web',
    permissions: [],
    users_count: 0,
    created_at: null,
    updated_at: null,
};

describe('RoleDialog permission matrix', () => {
    it('renders one row per domain with per-permission checkboxes', () => {
        wrap(
            <RoleDialog
                mode="edit"
                open
                role={EXISTING_ROLE}
                catalogue={CATALOGUE}
                onClose={() => undefined}
            />,
        );

        expect(screen.getByTestId('role-perm-domain-kb')).toBeInTheDocument();
        expect(screen.getByTestId('role-perm-domain-users')).toBeInTheDocument();
        expect(screen.getByTestId('role-perm-kb.read.any')).toHaveAttribute('data-active', 'false');
        expect(screen.getByTestId('role-perm-users.manage')).toHaveAttribute('data-active', 'false');
    });

    it('toggle-all flips every permission inside a domain', async () => {
        const user = userEvent.setup();
        wrap(
            <RoleDialog
                mode="edit"
                open
                role={EXISTING_ROLE}
                catalogue={CATALOGUE}
                onClose={() => undefined}
            />,
        );

        // Start: everything off.
        expect(screen.getByTestId('role-perm-kb.read.any')).toHaveAttribute('data-active', 'false');
        expect(screen.getByTestId('role-perm-kb.edit.any')).toHaveAttribute('data-active', 'false');
        expect(screen.getByTestId('role-perm-kb.delete.any')).toHaveAttribute('data-active', 'false');

        // Click toggle-all for kb.*.
        await user.click(screen.getByTestId('role-perm-kb-toggle-all'));

        expect(screen.getByTestId('role-perm-kb.read.any')).toHaveAttribute('data-active', 'true');
        expect(screen.getByTestId('role-perm-kb.edit.any')).toHaveAttribute('data-active', 'true');
        expect(screen.getByTestId('role-perm-kb.delete.any')).toHaveAttribute('data-active', 'true');
        // Untouched domain unaffected.
        expect(screen.getByTestId('role-perm-users.manage')).toHaveAttribute('data-active', 'false');

        // Click again — everything in the kb.* domain flips back off.
        await user.click(screen.getByTestId('role-perm-kb-toggle-all'));

        expect(screen.getByTestId('role-perm-kb.read.any')).toHaveAttribute('data-active', 'false');
        expect(screen.getByTestId('role-perm-kb.delete.any')).toHaveAttribute('data-active', 'false');
    });

    it('disables the name field for protected system roles', () => {
        const systemRole: AdminRole = { ...EXISTING_ROLE, id: 1, name: 'super-admin' };
        wrap(
            <RoleDialog
                mode="edit"
                open
                role={systemRole}
                catalogue={CATALOGUE}
                onClose={() => undefined}
            />,
        );
        expect(screen.getByTestId('role-dialog-name')).toBeDisabled();
        expect(screen.getByTestId('role-dialog-name-protected')).toBeInTheDocument();
    });
});
