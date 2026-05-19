import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    notificationsApi,
    type NotificationRow,
    type NotificationState,
} from './notifications.api';

/**
 * v8.0/W1.4 — /admin/notifications full panel.
 *
 * Tab navigation across `unread | read | dismissed | all`, optional
 * event_type filter, bulk mark-all-read, per-row mark-read +
 * dismiss. Polls the list every 30 s while the tab is visible.
 *
 * R29 testid hierarchy:
 *   - `notif-panel`                  top-level container
 *   - `notif-panel-tab-{state}`      tab button per state
 *   - `notif-panel-filter-event_type` event_type select
 *   - `notif-panel-mark-all-read`    bulk action button
 *   - `notif-panel-row-{id}-mark-read`
 *   - `notif-panel-row-{id}-dismiss`
 *   - `notif-panel-empty`            empty-state placeholder
 *   - `notif-panel-error`            error-state placeholder
 *   - `notif-panel-retry`            retry button on error
 */
export function NotificationPanel(): ReactNode {
    const qc = useQueryClient();
    const [state, setState] = useState<NotificationState>('unread');
    const [eventType, setEventType] = useState<string>('');

    const listQuery = useQuery({
        queryKey: ['notifications', 'panel', state, eventType],
        queryFn: () => notificationsApi.list({
            state,
            eventType: eventType || undefined,
            perPage: 20,
        }),
        refetchInterval: 30_000,
    });

    const markReadMut = useMutation({
        mutationFn: (id: number) => notificationsApi.markRead(id),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['notifications'] });
        },
    });

    const dismissMut = useMutation({
        mutationFn: (id: number) => notificationsApi.dismiss(id),
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

    const rows = listQuery.data?.data ?? [];
    const dataState = listQuery.isError
        ? 'error'
        : listQuery.isLoading
            ? 'loading'
            : rows.length === 0
                ? 'empty'
                : 'ready';

    return (
        <div data-testid="notif-panel" data-state={dataState} className="space-y-4 p-6">
            <h1 className="text-2xl font-semibold">Notifications</h1>

            <div className="flex flex-wrap items-center gap-2 border-b border-gray-200 pb-2">
                {(['unread', 'read', 'dismissed', 'all'] as NotificationState[]).map((s) => (
                    <button
                        key={s}
                        type="button"
                        data-testid={`notif-panel-tab-${s}`}
                        aria-pressed={state === s}
                        onClick={() => setState(s)}
                        className={`rounded px-3 py-1 text-sm ${
                            state === s
                                ? 'bg-blue-600 text-white'
                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                        }`}
                    >
                        {s.charAt(0).toUpperCase() + s.slice(1)}
                    </button>
                ))}

                <div className="ml-auto flex items-center gap-2">
                    <label htmlFor="notif-panel-event-type-select" className="text-sm text-gray-600">
                        Event type:
                    </label>
                    <select
                        id="notif-panel-event-type-select"
                        data-testid="notif-panel-filter-event_type"
                        aria-label="Filter by event type"
                        value={eventType}
                        onChange={(e) => setEventType(e.target.value)}
                        className="rounded border border-gray-300 px-2 py-1 text-sm"
                    >
                        <option value="">All event types</option>
                        <option value="kb_doc_created">Doc created</option>
                        <option value="kb_doc_modified">Doc modified</option>
                        <option value="kb_canonical_promoted">Canonical promoted</option>
                        <option value="kb_decision_debt_threshold">Decision debt threshold</option>
                        <option value="collection_new_member">Collection new member</option>
                    </select>

                    <button
                        type="button"
                        data-testid="notif-panel-mark-all-read"
                        onClick={() => markAllReadMut.mutate()}
                        disabled={markAllReadMut.isPending || state !== 'unread' || rows.length === 0}
                        className="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700 disabled:bg-gray-300"
                    >
                        Mark all read
                    </button>
                </div>
            </div>

            {dataState === 'loading' && (
                <p data-testid="notif-panel-loading" className="py-8 text-center text-gray-500">
                    Loading…
                </p>
            )}

            {dataState === 'error' && (
                <div data-testid="notif-panel-error" className="rounded border border-red-300 bg-red-50 p-4">
                    <p className="text-sm text-red-700">Could not load notifications.</p>
                    <button
                        type="button"
                        data-testid="notif-panel-retry"
                        onClick={() => void listQuery.refetch()}
                        className="mt-2 text-sm text-red-700 underline"
                    >
                        Retry
                    </button>
                </div>
            )}

            {dataState === 'empty' && (
                <p data-testid="notif-panel-empty" className="py-8 text-center text-gray-500">
                    No notifications in this view.
                </p>
            )}

            {dataState === 'ready' && (
                <ul className="divide-y divide-gray-100 rounded border border-gray-200">
                    {rows.map((row: NotificationRow) => (
                        <li key={row.id} className="p-3">
                            <div className="flex items-start justify-between gap-2">
                                <div className="flex-1">
                                    <div className="text-sm font-medium">{summariseEvent(row)}</div>
                                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                        <span>{new Date(row.created_at).toLocaleString()}</span>
                                        <code>{row.event_type}</code>
                                        {row.read_at && <span>read {new Date(row.read_at).toLocaleString()}</span>}
                                        {row.dismissed_at && <span>dismissed</span>}
                                    </div>
                                </div>
                                <div className="flex flex-col gap-1">
                                    {row.read_at === null && (
                                        <button
                                            type="button"
                                            data-testid={`notif-panel-row-${row.id}-mark-read`}
                                            onClick={() => markReadMut.mutate(row.id)}
                                            disabled={markReadMut.isPending}
                                            className="text-xs text-blue-600 hover:underline"
                                        >
                                            Mark read
                                        </button>
                                    )}
                                    {row.dismissed_at === null && (
                                        <button
                                            type="button"
                                            data-testid={`notif-panel-row-${row.id}-dismiss`}
                                            onClick={() => dismissMut.mutate(row.id)}
                                            disabled={dismissMut.isPending}
                                            className="text-xs text-gray-600 hover:underline"
                                        >
                                            Dismiss
                                        </button>
                                    )}
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
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
            return `Document updated: ${String(payload.title ?? payload.source_path ?? 'untitled')}`;
        case 'kb_canonical_promoted':
            return `Canonical promoted: ${String(payload.slug ?? '?')}`;
        case 'kb_decision_debt_threshold':
            return 'Decision debt threshold reached';
        case 'collection_new_member':
            return 'New member added to a collection';
        default:
            return String(row.event_type);
    }
}
