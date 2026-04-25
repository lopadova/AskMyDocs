import type { AdminUser } from '../admin.api';
import { UsersTableRow } from './UsersTableRow';

export type UsersTableState = 'loading' | 'ready' | 'error' | 'empty';

export interface UsersTableProps {
    users: AdminUser[];
    state: UsersTableState;
    onOpen: (user: AdminUser) => void;
    onToggleActive: (user: AdminUser) => void;
    onRestore?: (user: AdminUser) => void;
    onDelete: (user: AdminUser) => void;
}

/*
 * Hand-rolled table — no TanStack Table dep. Carries a `data-state`
 * root attribute so E2E can gate on it. Empty + error + loading are
 * first-class states so the shell never flashes partial content.
 */
export function UsersTable({
    users,
    state,
    onOpen,
    onToggleActive,
    onRestore,
    onDelete,
}: UsersTableProps) {
    return (
        <div
            data-testid="users-table"
            data-state={state}
            style={{
                border: '1px solid var(--hairline)',
                borderRadius: 10,
                overflow: 'hidden',
                background: 'var(--bg-1)',
            }}
        >
            <table
                style={{
                    width: '100%',
                    borderCollapse: 'collapse',
                    fontFamily: 'var(--font-sans)',
                }}
            >
                <thead>
                    <tr style={{ background: 'var(--bg-0)' }}>
                        <th style={thStyle}>Name</th>
                        <th style={thStyle}>Email</th>
                        <th style={thStyle}>Roles</th>
                        <th style={thStyle}>Status</th>
                        <th style={{ ...thStyle, textAlign: 'right' }}>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {state === 'loading' ? (
                        <tr>
                            <td
                                colSpan={5}
                                data-testid="users-loading"
                                style={emptyCellStyle}
                            >
                                <span className="shimmer" style={{ padding: '4px 14px' }}>
                                    Loading users…
                                </span>
                            </td>
                        </tr>
                    ) : state === 'error' ? (
                        <tr>
                            <td
                                colSpan={5}
                                data-testid="users-error"
                                style={{ ...emptyCellStyle, color: '#fca5a5' }}
                            >
                                Failed to load users. Retry or reload the page.
                            </td>
                        </tr>
                    ) : state === 'empty' ? (
                        <tr>
                            <td
                                colSpan={5}
                                data-testid="users-empty"
                                style={emptyCellStyle}
                            >
                                No users match the current filter.
                            </td>
                        </tr>
                    ) : (
                        users.map((u) => (
                            <UsersTableRow
                                key={u.id}
                                user={u}
                                onOpen={onOpen}
                                onToggleActive={onToggleActive}
                                onRestore={onRestore}
                                onDelete={onDelete}
                            />
                        ))
                    )}
                </tbody>
            </table>
        </div>
    );
}

const thStyle = {
    padding: '10px 12px',
    textAlign: 'left' as const,
    fontSize: 11,
    color: 'var(--fg-3)',
    fontFamily: 'var(--font-mono)',
    textTransform: 'uppercase' as const,
    letterSpacing: '0.05em',
    fontWeight: 500,
    borderBottom: '1px solid var(--hairline)',
};

const emptyCellStyle = {
    padding: '32px 16px',
    textAlign: 'center' as const,
    color: 'var(--fg-3)',
    fontSize: 13,
};
