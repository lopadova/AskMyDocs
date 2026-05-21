import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { notificationsApi, type NotificationRow } from './notifications.api';
import { summariseNotificationEvent } from './summarise';

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
        <div className="relative inline-block">
            <button
                type="button"
                data-testid="notif-bell"
                data-state={state}
                aria-busy={isBusy}
                aria-label={`Notifications (${unread} unread)`}
                aria-expanded={open}
                aria-haspopup="dialog"
                onClick={() => setOpen((o) => !o)}
                className="relative inline-flex items-center justify-center rounded p-2 hover:bg-gray-100"
            >
                <span aria-hidden="true">🔔</span>
                {unread > 0 && (
                    <span
                        data-testid="notif-bell-badge"
                        aria-hidden="true"
                        className="absolute -right-1 -top-1 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-600 px-1 text-xs font-semibold text-white"
                    >
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </button>

            {state === 'error' && (
                <button
                    type="button"
                    data-testid="notif-bell-retry"
                    onClick={() => void countQuery.refetch()}
                    className="ml-2 text-xs text-red-600 underline"
                >
                    Retry
                </button>
            )}

            {open && (
                <div
                    data-testid="notif-bell-dropdown"
                    data-state={dropdownState}
                    role="dialog"
                    aria-label="Notifications"
                    aria-busy={listQuery.isFetching}
                    className="absolute right-0 z-50 mt-2 w-80 rounded border border-gray-200 bg-white shadow-lg"
                >
                    <div className="flex items-center justify-between border-b border-gray-100 px-3 py-2">
                        <span className="text-sm font-semibold">Notifications</span>
                        <button
                            type="button"
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
                            className="text-xs text-blue-600 hover:underline disabled:text-gray-400"
                        >
                            Mark all read
                        </button>
                    </div>

                    {actionError && (
                        <div
                            data-testid="notif-bell-action-error"
                            role="alert"
                            className="border-b border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"
                        >
                            Action failed. Please retry.
                            <button
                                type="button"
                                data-testid="notif-bell-action-error-dismiss"
                                onClick={() => {
                                    markReadMut.reset();
                                    markAllReadMut.reset();
                                }}
                                className="ml-2 underline"
                            >
                                Dismiss
                            </button>
                        </div>
                    )}

                    <ul className="max-h-80 divide-y divide-gray-100 overflow-y-auto">
                        {listQuery.isLoading && (
                            <li data-testid="notif-bell-loading" className="px-3 py-4 text-center text-sm text-gray-500">
                                Loading…
                            </li>
                        )}
                        {listQuery.isError && (
                            <li
                                data-testid="notif-bell-list-error"
                                role="alert"
                                className="px-3 py-4 text-center text-sm text-red-700"
                            >
                                Could not load notifications.
                                <button
                                    type="button"
                                    data-testid="notif-bell-list-retry"
                                    onClick={() => void listQuery.refetch()}
                                    className="ml-2 underline"
                                >
                                    Retry
                                </button>
                            </li>
                        )}
                        {!listQuery.isLoading && !listQuery.isError && (listQuery.data?.data ?? []).length === 0 && (
                            <li data-testid="notif-bell-empty" className="px-3 py-4 text-center text-sm text-gray-500">
                                No unread notifications
                            </li>
                        )}
                        {!listQuery.isError && (listQuery.data?.data ?? []).map((row: NotificationRow) => (
                            <li key={row.id} className="px-3 py-2">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex-1 text-sm">
                                        <div className="font-medium">{summariseNotificationEvent(row)}</div>
                                        <div className="text-xs text-gray-500">{new Date(row.created_at).toLocaleString()}</div>
                                    </div>
                                    <button
                                        type="button"
                                        data-testid={`notif-bell-row-${row.id}-mark-read`}
                                        onClick={() => markReadMut.mutate(row.id)}
                                        disabled={markReadMut.isPending}
                                        className="text-xs text-blue-600 hover:underline"
                                    >
                                        Mark read
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>

                    <div className="border-t border-gray-100 px-3 py-2 text-center">
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
                            href="/app/admin/notifications"
                            className="text-sm text-blue-600 hover:underline"
                            onClick={() => setOpen(false)}
                        >
                            See all
                        </a>
                    </div>
                </div>
            )}
        </div>
    );
}

