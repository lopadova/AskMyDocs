import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    notificationsApi,
    type NotificationRow,
    type NotificationState,
} from './notifications.api';

/**
 * v8.0/W1.4 — /app/admin/notifications full panel.
 *
 * Tab navigation across `unread | read | dismissed | all`, optional
 * event_type filter, paginated list (20 per page), bulk mark-all-read
 * scoped to the active filter (Copilot iter-2 #3), per-row mark-read +
 * dismiss. Polls the list every 30 s while the tab is visible.
 *
 * R29 testid hierarchy:
 *   - `notif-panel`                     top-level container
 *   - `notif-panel-tab-{state}`         tab button per state
 *   - `notif-panel-filter-event_type`   event_type select
 *   - `notif-panel-mark-all-read`       bulk action button
 *   - `notif-panel-row-{id}-mark-read`
 *   - `notif-panel-row-{id}-dismiss`
 *   - `notif-panel-empty`               empty-state placeholder
 *   - `notif-panel-error`               error-state placeholder
 *   - `notif-panel-retry`               retry button on error
 *   - `notif-panel-action-error`        inline banner for mutation
 *                                       failures (Copilot iter-2 #4)
 *   - `notif-panel-page-prev` / `-next` pagination controls
 *   - `notif-panel-page-status`         "Page N of M (T total)"
 *
 * R15 a11y: container exposes `aria-busy` while the list query is
 * loading or refetching (Copilot iter-2 #6).
 *
 * Event-type options merge a static AskMyDocs-known vocabulary with
 * any event_type observed in the current page response, so newly-
 * shipped event types automatically appear in the dropdown without a
 * FE redeploy (Copilot iter-2 #11). A dedicated FE-FE constants file
 * is intentionally avoided — the BE remains source-of-truth via
 * `App\Models\NotificationEvent::EVENT_*`.
 */

const KNOWN_EVENT_TYPES = [
    { value: 'kb_doc_created', label: 'Doc created' },
    { value: 'kb_doc_modified', label: 'Doc modified' },
    { value: 'kb_canonical_promoted', label: 'Canonical promoted' },
    { value: 'kb_decision_debt_threshold', label: 'Decision debt threshold' },
    { value: 'collection_new_member', label: 'Collection new member' },
];

export function NotificationPanel(): ReactNode {
    const qc = useQueryClient();
    const [state, setState] = useState<NotificationState>('unread');
    const [eventType, setEventType] = useState<string>('');
    const [page, setPage] = useState<number>(1);

    const listQuery = useQuery({
        queryKey: ['notifications', 'panel', state, eventType, page],
        queryFn: () => notificationsApi.list({
            state,
            eventType: eventType || undefined,
            perPage: 20,
            page,
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
        // Copilot iter-2 #3 — forward the current event_type filter so
        // the BE only flips rows the user can actually see in this view.
        mutationFn: () => notificationsApi.markAllRead({ eventType: eventType || undefined }),
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['notifications'] });
        },
    });

    const rows = listQuery.data?.data ?? [];
    const meta = listQuery.data?.meta;
    // Copilot iter-5 #2/#8 — distinguish "tenant has no notifications
    // in this view" from "user is on an out-of-range page". The
    // former → empty state. The latter → snap-back effect below
    // pulls the user to the last valid page and avoids a misleading
    // empty state when earlier pages still contain rows.
    const totalForView = meta?.total ?? 0;
    const dataState = listQuery.isError
        ? 'error'
        : listQuery.isLoading
            ? 'loading'
            : totalForView === 0
                ? 'empty'
                : 'ready';
    const actionError = markReadMut.error ?? dismissMut.error ?? markAllReadMut.error;

    // Copilot iter-5 #2 — snap the current page back to last_page
    // when a mutation (mark-read, dismiss, mark-all-read) shrinks
    // the result set so the user lands on now-out-of-range pages.
    // Without this, marking the only row on page 3 of a 3-page
    // unread view leaves the user stuck on an "empty" page 3 even
    // though page 1 + 2 still have rows.
    useEffect(() => {
        if (!meta) return;
        if (page > meta.last_page && meta.last_page >= 1) {
            setPage(meta.last_page);
        }
    }, [meta, page]);

    // Copilot iter-4 #1 — fetch the canonical event_type list from the
    // BE (R18 — derive options from the DB, not from a literal subset).
    // Falls back to the static AskMyDocs-known vocabulary if the query
    // is still loading or errors out (graceful degradation).
    const eventTypesQuery = useQuery({
        queryKey: ['notifications', 'event-types'],
        queryFn: () => notificationsApi.eventTypes(),
        staleTime: 60_000,
    });

    const eventTypeOptions = useMemo(() => {
        const knownByValue = new Map(KNOWN_EVENT_TYPES.map((o) => [o.value, o.label]));
        const sources: string[] = [];
        if (eventTypesQuery.data && eventTypesQuery.data.length > 0) {
            sources.push(...eventTypesQuery.data);
        } else {
            sources.push(...KNOWN_EVENT_TYPES.map((o) => o.value));
            // Augment with current-page rows (Copilot iter-3 #8 — dedup
            // by mutating the seen set as we walk). Only used as the
            // fallback path while the dedicated endpoint is unavailable.
            const seen = new Set(sources);
            for (const row of rows) {
                const t = row.event_type;
                if (typeof t === 'string' && !seen.has(t)) {
                    seen.add(t);
                    sources.push(t);
                }
            }
        }
        return sources.map((value) => ({
            value,
            label: knownByValue.get(value) ?? value,
        }));
    }, [eventTypesQuery.data, rows]);

    const lastPage = meta?.last_page ?? 1;
    const total = meta?.total ?? 0;
    const currentPage = meta?.current_page ?? page;

    return (
        <div
            data-testid="notif-panel"
            data-state={dataState}
            aria-busy={listQuery.isFetching}
            className="space-y-4 p-6"
        >
            <h1 className="text-2xl font-semibold">Notifications</h1>

            <div className="flex flex-wrap items-center gap-2 border-b border-gray-200 pb-2">
                {(['unread', 'read', 'dismissed', 'all'] as NotificationState[]).map((s) => (
                    <button
                        key={s}
                        type="button"
                        data-testid={`notif-panel-tab-${s}`}
                        aria-pressed={state === s}
                        onClick={() => {
                            setState(s);
                            setPage(1);
                        }}
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
                        onChange={(e) => {
                            setEventType(e.target.value);
                            setPage(1);
                        }}
                        className="rounded border border-gray-300 px-2 py-1 text-sm"
                    >
                        <option value="">All event types</option>
                        {eventTypeOptions.map((opt) => (
                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                        ))}
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

            {actionError && (
                <div
                    data-testid="notif-panel-action-error"
                    role="alert"
                    className="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700"
                >
                    Action failed. Please retry.
                    <button
                        type="button"
                        data-testid="notif-panel-action-error-dismiss"
                        onClick={() => {
                            markReadMut.reset();
                            dismissMut.reset();
                            markAllReadMut.reset();
                        }}
                        className="ml-2 underline"
                    >
                        Dismiss
                    </button>
                </div>
            )}

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

            {meta && (
                <div className="flex items-center justify-between border-t border-gray-100 pt-2 text-sm text-gray-600">
                    <span data-testid="notif-panel-page-status">
                        Page {currentPage} of {lastPage} ({total} total)
                    </span>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            data-testid="notif-panel-page-prev"
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            disabled={currentPage <= 1 || listQuery.isFetching}
                            className="rounded border border-gray-300 px-3 py-1 text-sm disabled:text-gray-400"
                        >
                            Prev
                        </button>
                        <button
                            type="button"
                            data-testid="notif-panel-page-next"
                            onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                            disabled={currentPage >= lastPage || listQuery.isFetching}
                            className="rounded border border-gray-300 px-3 py-1 text-sm disabled:text-gray-400"
                        >
                            Next
                        </button>
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
