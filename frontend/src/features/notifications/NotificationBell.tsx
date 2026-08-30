import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { notificationsApi, type NotificationRow } from './notifications.api';
import { summariseNotificationEvent } from './summarise';
import { selectCurrentHash, useTeamStore } from '../../lib/team-store';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icons';

/**
 * v8.0/W1.4 — Top-bar notification bell.
 *
 * Polls `/api/notifications/unread-count` every 30 s (TanStack
 * Query `refetchInterval`). Clicking the bell opens a dropdown
 * with the last 5 unread notifications + a "See all" link to
 * `/app/admin/notifications` (the full panel, separate component).
 *
 * R29 testid hierarchy:
 *   - `notif-bell`              top-level button (opens dropdown)
 *   - `notif-bell-badge`        unread count badge
 *   - `notif-bell-dropdown`     dropdown container (only when open)
 *   - `notif-bell-row-{id}-mark-read`
 *   - `notif-bell-empty`        "no unread" state inside dropdown
 *   - `notif-bell-see-all`      link to /app/admin/notifications
 *   - `notif-bell-mark-all-read`
 *   - `notif-bell-list-error`   dropdown error placeholder when
 *                               the list query itself fails
 *   - `notif-bell-action-error` inline banner shown when a
 *                               mark-read / mark-all-read mutation
 *                               fails (Copilot iter-2 #5)
 *
 * R14: API errors on the count query set `data-state="error"` on
 * the bell button + show a retry. List-query failures render an
 * explicit error placeholder (NOT the empty state — Copilot iter-2
 * #1). Mutation failures surface an inline banner with retry
 * guidance (Copilot iter-2 #5).
 *
 * R15 a11y: bell button + dropdown container both expose
 * `aria-busy` while the unread-count / list queries are loading
 * or refetching (Copilot iter-2 #7).
 */
export function NotificationBell(): ReactNode {
    const qc = useQueryClient();
    const [open, setOpen] = useState(false);
    const teamHash = useTeamStore(selectCurrentHash) ?? '';
    // Fall back to the legacy non-team URL when no hash is available so the
    // link is never a broken double-slash path like `/app//admin/notifications`.
    const notificationsHref = teamHash
        ? `/app/${teamHash}/admin/notifications`
        : '/app/admin/notifications';

    const countQuery = useQuery({
        queryKey: ['notifications', 'unread-count'],
        queryFn: () => notificationsApi.unreadCount(),
        refetchInterval: 30_000,
        refetchOnWindowFocus: true,
    });

    const listQuery = useQuery({
        queryKey: ['notifications', 'unread', 'top5'],
        queryFn: () => notificationsApi.list({ state: 'unread', perPage: 5 }),
        enabled: open,
        refetchInterval: open ? 30_000 : false,
    });

    const markReadMut = useMutation({
        mutationFn: (id: number) => notificationsApi.markRead(id),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['notifications'] });
        },
    });

    const markAllReadMut = useMutation({
        mutationFn: () => notificationsApi.markAllRead(),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['notifications'] });
        },
    });

    const state = countQuery.isError
        ? 'error'
        : countQuery.isLoading
            ? 'loading'
            : 'ready';
    // Copilot iter-4 #5 — R11 expects observable async state on every
    // FE container. The dropdown is its own async surface (separate
    // queryKey from the bell-button count) and now exposes its own
    // data-state alongside aria-busy so E2E waits are deterministic.
    const dropdownState = listQuery.isError
        ? 'error'
        : listQuery.isLoading
            ? 'loading'
            : (listQuery.data?.data ?? []).length === 0
                ? 'empty'
                : 'ready';
    const unread = countQuery.data ?? 0;
    const isBusy = countQuery.isFetching || listQuery.isFetching;
    const actionError = markReadMut.error ?? markAllReadMut.error;

    return (
        <div className="notification-bell">
            <Button
                variant={open ? 'secondary' : 'quiet'}
                size="sm"
                iconOnly
                className="app-topbar-icon-button notification-bell-trigger"
                data-testid="notif-bell"
                data-state={state}
                aria-busy={isBusy}
                aria-label={`Notifications (${unread} unread)`}
                aria-expanded={open}
                aria-haspopup="dialog"
                onClick={() => setOpen((o) => !o)}
            >
                <Icon.Bell size={15} />
                {unread > 0 && (
                    <span
                        data-testid="notif-bell-badge"
                        aria-hidden="true"
                        className="notification-bell-badge"
                    >
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </Button>

            {state === 'error' && (
                <Button
                    variant="quiet"
                    size="sm"
                    data-testid="notif-bell-retry"
                    onClick={() => void countQuery.refetch()}
                    className="notification-bell-retry"
                >
                    Retry
                </Button>
            )}

            {open && (
                <div
                    data-testid="notif-bell-dropdown"
                    data-state={dropdownState}
                    role="dialog"
                    aria-label="Notifications"
                    aria-busy={listQuery.isFetching}
                    className="notification-popover"
                >
                    <div className="notification-popover-header">
                        <div>
                            <span className="notification-popover-eyebrow">Workspace</span>
                            <strong>Notifications</strong>
                        </div>
                        <Button
                            variant="quiet"
                            size="sm"
                            data-testid="notif-bell-mark-all-read"
                            onClick={() => markAllReadMut.mutate()}
                            // Copilot iter-5 #1 — the bulk button must
                            // not be paralysed by a count-only outage.
                            // Enable when EITHER the count query
                            // confirms unread > 0, OR the dropdown
                            // list already has visible rows the user
                            // can act on (independent failure modes).
                            disabled={
                                markAllReadMut.isPending
                                || (unread === 0 && (listQuery.data?.data ?? []).length === 0)
                            }
                        >
                            Mark all read
                        </Button>
                    </div>

                    {actionError && (
                        <div
                            data-testid="notif-bell-action-error"
                            role="alert"
                            className="notification-popover-alert"
                        >
                            <span>Action failed. Please retry.</span>
                            <Button
                                variant="quiet"
                                size="sm"
                                data-testid="notif-bell-action-error-dismiss"
                                onClick={() => {
                                    markReadMut.reset();
                                    markAllReadMut.reset();
                                }}
                            >
                                Dismiss
                            </Button>
                        </div>
                    )}

                    <ul className="notification-popover-list">
                        {listQuery.isLoading && (
                            <li data-testid="notif-bell-loading" className="notification-popover-state">
                                Loading…
                            </li>
                        )}
                        {listQuery.isError && (
                            <li
                                data-testid="notif-bell-list-error"
                                role="alert"
                                className="notification-popover-state is-error"
                            >
                                <span>Could not load notifications.</span>
                                <Button
                                    variant="quiet"
                                    size="sm"
                                    data-testid="notif-bell-list-retry"
                                    onClick={() => void listQuery.refetch()}
                                >
                                    Retry
                                </Button>
                            </li>
                        )}
                        {!listQuery.isLoading && !listQuery.isError && (listQuery.data?.data ?? []).length === 0 && (
                            <li data-testid="notif-bell-empty" className="notification-popover-empty">
                                <span aria-hidden="true"><Icon.Check size={16} /></span>
                                <strong>You’re all caught up</strong>
                                <small>No unread notifications</small>
                            </li>
                        )}
                        {!listQuery.isError && (listQuery.data?.data ?? []).map((row: NotificationRow) => (
                            <li key={row.id} className="notification-popover-row">
                                <span className="notification-popover-row-icon" aria-hidden="true">
                                    <Icon.Bell size={13} />
                                </span>
                                <div className="notification-popover-row-body">
                                    <div className="notification-popover-row-copy">
                                        <strong>{summariseNotificationEvent(row)}</strong>
                                        <small>{new Date(row.created_at).toLocaleString()}</small>
                                    </div>
                                    <Button
                                        variant="quiet"
                                        size="sm"
                                        data-testid={`notif-bell-row-${row.id}-mark-read`}
                                        onClick={() => markReadMut.mutate(row.id)}
                                        disabled={markReadMut.isPending}
                                    >
                                        Mark read
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>

                    <div className="notification-popover-footer">
                        {/* Copilot iter-5 #6 — kept as <a href> rather
                          * than `<Link>` so the bell stays renderable
                          * in Vitest without a `<RouterProvider>` test
                          * harness. The Bell is the only feature
                          * widget mounted in the Topbar of every
                          * authenticated page, and wrapping every
                          * existing Topbar-using Vitest in router
                          * context is out of scope for W1.4. The
                          * navigation hits the same SPA bundle so
                          * the "full reload" cost is bootstrap-only
                          * (auth /me + initial route resolve), not a
                          * server round-trip per click. A follow-up
                          * sub-PR can introduce a shared `Router`
                          * test wrapper and migrate this + other
                          * navigation links to `<Link>` in lockstep. */}
                        <a
                            data-testid="notif-bell-see-all"
                            href={notificationsHref}
                            className="notification-see-all"
                            onClick={() => setOpen(false)}
                        >
                            <span>See all notifications</span>
                            <Icon.Chevron size={13} />
                        </a>
                    </div>
                </div>
            )}
        </div>
    );
}
