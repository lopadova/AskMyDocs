import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { UsersTable } from './UsersTable';
import type { AdminUser } from '../admin.api';

function makeUser(overrides: Partial<AdminUser> = {}): AdminUser {
    return {
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
        ...overrides,
    };
}

describe('UsersTable', () => {
    const noop = () => {};

    it('renders loading shimmer and reports data-state=loading', () => {
        render(
            <UsersTable
                users={[]}
                state="loading"
                onOpen={noop}
                onToggleActive={noop}
                onDelete={noop}
            />,
        );
        expect(screen.getByTestId('users-table')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('users-loading')).toBeInTheDocument();
    });

    it('renders an empty cell with data-state=empty', () => {
        render(
            <UsersTable
                users={[]}
                state="empty"
                onOpen={noop}
                onToggleActive={noop}
                onDelete={noop}
            />,
        );
        expect(screen.getByTestId('users-table')).toHaveAttribute('data-state', 'empty');
        expect(screen.getByTestId('users-empty')).toBeInTheDocument();
    });

    it('renders the error surface with data-state=error', () => {
        render(
            <UsersTable
                users={[]}
                state="error"
                onOpen={noop}
                onToggleActive={noop}
                onDelete={noop}
            />,
        );
        expect(screen.getByTestId('users-table')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('users-error')).toBeInTheDocument();
    });

    it('renders one row per user when ready', () => {
        const users = [
            makeUser({ id: 1, name: 'Alice', email: 'alice@demo.local' }),
            makeUser({ id: 2, name: 'Bob', email: 'bob@demo.local', roles: ['editor'] }),
        ];
        render(
            <UsersTable
                users={users}
                state="ready"
                onOpen={noop}
                onToggleActive={noop}
                onDelete={noop}
            />,
        );
        expect(screen.getByTestId('users-table')).toHaveAttribute('data-state', 'ready');
        expect(screen.getByTestId('users-row-1')).toBeInTheDocument();
        expect(screen.getByTestId('users-row-2')).toBeInTheDocument();
        expect(screen.getByTestId('users-row-1-email')).toHaveTextContent('alice@demo.local');
    });

    it('surfaces restore action and NO delete button for trashed rows', () => {
        const user = makeUser({
            id: 42,
            name: 'Ghost',
            email: 'ghost@demo.local',
            deleted_at: '2026-01-01T00:00:00Z',
        });
        const onRestore = vi.fn();
        render(
            <UsersTable
                users={[user]}
                state="ready"
                onOpen={noop}
                onToggleActive={noop}
                onRestore={onRestore}
                onDelete={noop}
            />,
        );
        const row = screen.getByTestId('users-row-42');
        expect(row).toHaveAttribute('data-trashed', 'true');
        expect(screen.getByTestId('users-row-42-restore')).toBeInTheDocument();
        expect(screen.queryByTestId('users-row-42-delete')).toBeNull();

        fireEvent.click(screen.getByTestId('users-row-42-restore'));
        expect(onRestore).toHaveBeenCalledWith(user);
    });
});
