import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { notificationsApi, type NotificationRow } from './notifications.api';

/**
 * v8.0/W1.4 — Top-bar notification bell.
 *
 * Polls `/api/notifications/unread-count` every 30 s (TanStack
 * Query `refetchInterval`). Clicking the bell opens a dropdown
 * with the last 5 unread notifications + a "see all" link to
 * `/admin/notifications` (the full panel, separate component).
 *
 * R29 testid hierarchy:
 *   - `notif-bell`              top-level button (opens dropdown)
 *   - `notif-bell-badge`        unread count badge
 *   - `notif-bell-dropdown`     dropdown container (only when open)
 *   - `notif-bell-row-{id}-mark-read`
 *   - `notif-bell-empty`        "no unread" state inside dropdown
 *   - `notif-bell-see-all`      link to /admin/notifications
 *   - `notif-bell-mark-all-read`
 *
 * R14: API errors set `data-state="error"` + show a retry button
 * (the dropdown's caller can read the state and show inline error).
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
    const unread = countQuery.data ?? 0;

    return (
        <div className="relative inline-block">
            <button
                type="button"
                data-testid="notif-bell"
                data-state={state}
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
                    role="dialog"
                    aria-label="Notifications"
                    className="absolute right-0 z-50 mt-2 w-80 rounded border border-gray-200 bg-white shadow-lg"
                >
                    <div className="flex items-center justify-between border-b border-gray-100 px-3 py-2">
                        <span className="text-sm font-semibold">Notifications</span>
                        <button
                            type="button"
                            data-testid="notif-bell-mark-all-read"
                            onClick={() => markAllReadMut.mutate()}
                            disabled={markAllReadMut.isPending || unread === 0}
                            className="text-xs text-blue-600 hover:underline disabled:text-gray-400"
                        >
                            Mark all read
                        </button>
                    </div>

                    <ul className="max-h-80 divide-y divide-gray-100 overflow-y-auto">
                        {listQuery.isLoading && (
                            <li data-testid="notif-bell-loading" className="px-3 py-4 text-center text-sm text-gray-500">
                                Loading…
                            </li>
                        )}
                        {!listQuery.isLoading && (listQuery.data?.data ?? []).length === 0 && (
                            <li data-testid="notif-bell-empty" className="px-3 py-4 text-center text-sm text-gray-500">
                                No unread notifications
                            </li>
                        )}
                        {(listQuery.data?.data ?? []).map((row: NotificationRow) => (
                            <li key={row.id} className="px-3 py-2">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex-1 text-sm">
                                        <div className="font-medium">{summariseEvent(row)}</div>
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
                        {/*
                          v8.0/W1.4 — the SPA shell catches /app/* so the
                          link must carry the /app prefix. The actual
                          TanStack Router mount of `/app/admin/notifications`
                          (rendering NotificationPanel) is W1.4.x scope —
                          this PR ships the component + backend; the
                          AdminShell sidebar wire-up + route registration
                          lands in the follow-up sub-task.
                        */}
                        <a
                            data-testid="notif-bell-see-all"
                            href="/app/admin/notifications"
                            className="text-sm text-blue-600 hover:underline"
                        >
                            See all
                        </a>
                    </div>
                </div>
            )}
        </div>
    );
}

function summariseEvent(row: NotificationRow): string {
    const payload = row.payload ?? {};
    switch (row.event_type) {
        case 'kb_doc_created':
            return `New document: ${String(payload.title ?? payload.source_path ?? 'untitled')}`;
        case 'kb_doc_modified':
            return `Updated: ${String(payload.title ?? payload.source_path ?? 'untitled')}`;
        case 'kb_canonical_promoted':
            return `Promoted to canonical: ${String(payload.slug ?? '?')}`;
        case 'kb_decision_debt_threshold':
            return 'Decision debt threshold reached';
        case 'collection_new_member':
            return 'New member added to a collection';
        default:
            return String(row.event_type);
    }
}
