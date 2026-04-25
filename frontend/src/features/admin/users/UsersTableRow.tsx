import { Icon } from '../../../components/Icons';
import type { AdminUser } from '../admin.api';

export interface UsersTableRowProps {
    user: AdminUser;
    onOpen: (user: AdminUser) => void;
    onToggleActive: (user: AdminUser) => void;
    onRestore?: (user: AdminUser) => void;
    onDelete: (user: AdminUser) => void;
}

/*
 * Single row. Trashed rows render with a muted style and expose a
 * `users-row-<id>-restore` button instead of the usual delete / edit
 * actions — matches the expected drawer UX.
 */
export function UsersTableRow({
    user,
    onOpen,
    onToggleActive,
    onRestore,
    onDelete,
}: UsersTableRowProps) {
    const trashed = user.deleted_at !== null;

    return (
        <tr
            data-testid={`users-row-${user.id}`}
            data-trashed={trashed ? 'true' : 'false'}
            style={{
                borderBottom: '1px solid var(--hairline)',
                background: trashed ? 'rgba(239,68,68,0.05)' : 'transparent',
            }}
        >
            <td style={{ padding: '10px 12px', fontSize: 13 }}>
                <button
                    type="button"
                    className="focus-ring"
                    data-testid={`users-row-${user.id}-open`}
                    onClick={() => onOpen(user)}
                    style={{
                        background: 'transparent',
                        border: 'none',
                        color: trashed ? 'var(--fg-3)' : 'var(--fg-0)',
                        padding: 0,
                        fontSize: 13,
                        cursor: 'pointer',
                        fontWeight: 500,
                        textAlign: 'left',
                    }}
                >
                    {user.name}
                </button>
            </td>
            <td
                data-testid={`users-row-${user.id}-email`}
                style={{ padding: '10px 12px', fontSize: 13, color: 'var(--fg-2)' }}
            >
                {user.email}
            </td>
            <td style={{ padding: '10px 12px', fontSize: 12, color: 'var(--fg-2)' }}>
                <div
                    data-testid={`users-row-${user.id}-roles`}
                    style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}
                >
                    {user.roles.length === 0 ? (
                        <span style={{ color: 'var(--fg-3)' }}>—</span>
                    ) : (
                        user.roles.map((r) => (
                            <span
                                key={r}
                                style={{
                                    padding: '2px 7px',
                                    borderRadius: 999,
                                    background: 'var(--grad-accent-soft)',
                                    fontSize: 11,
                                    color: 'var(--fg-1)',
                                }}
                            >
                                {r}
                            </span>
                        ))
                    )}
                </div>
            </td>
            <td style={{ padding: '10px 12px', fontSize: 12 }}>
                <span
                    data-testid={`users-row-${user.id}-active`}
                    data-active={user.is_active ? 'true' : 'false'}
                    style={{
                        padding: '2px 7px',
                        borderRadius: 999,
                        background: user.is_active
                            ? 'rgba(16,185,129,0.16)'
                            : 'rgba(148,163,184,0.18)',
                        color: user.is_active ? '#6ee7b7' : 'var(--fg-3)',
                        fontSize: 11,
                    }}
                >
                    {user.is_active ? 'active' : 'inactive'}
                </span>
            </td>
            <td style={{ padding: '10px 12px', textAlign: 'right' }}>
                <div style={{ display: 'inline-flex', gap: 6 }}>
                    {trashed ? (
                        <button
                            type="button"
                            className="focus-ring"
                            data-testid={`users-row-${user.id}-restore`}
                            onClick={() => onRestore?.(user)}
                            title="Restore user"
                            style={iconButtonStyle}
                        >
                            <Icon.Check size={14} /> Restore
                        </button>
                    ) : (
                        <>
                            <button
                                type="button"
                                className="focus-ring"
                                data-testid={`users-row-${user.id}-toggle-active`}
                                onClick={() => onToggleActive(user)}
                                title={user.is_active ? 'Deactivate' : 'Activate'}
                                style={iconButtonStyle}
                            >
                                <Icon.Bolt size={14} />
                            </button>
                            <button
                                type="button"
                                className="focus-ring"
                                data-testid={`users-row-${user.id}-edit`}
                                onClick={() => onOpen(user)}
                                title="Edit"
                                style={iconButtonStyle}
                            >
                                <Icon.Edit size={14} />
                            </button>
                            <button
                                type="button"
                                className="focus-ring"
                                data-testid={`users-row-${user.id}-delete`}
                                onClick={() => onDelete(user)}
                                title="Delete"
                                style={{ ...iconButtonStyle, color: '#fca5a5' }}
                            >
                                <Icon.Trash size={14} />
                            </button>
                        </>
                    )}
                </div>
            </td>
        </tr>
    );
}

const iconButtonStyle = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 4,
    padding: '5px 8px',
    borderRadius: 6,
    background: 'transparent',
    border: '1px solid var(--hairline)',
    color: 'var(--fg-2)',
    cursor: 'pointer',
    fontSize: 12,
} as const;
