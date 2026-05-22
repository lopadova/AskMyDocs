import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    notificationsApi,
    type NotificationPreferenceRow,
    type NotificationPreferencesResponse,
} from './notifications.api';
import { EVENT_TYPE_LABELS, CHANNEL_LABELS } from './labels';
import {
    chatPreferencesApi,
    CHAT_PREFERENCES_QUERY_KEY,
    type ChatPreferencesResponse,
} from '../chat/chat-preferences.api';

/**
 * v8.0/W2.2 — /app/admin/notifications/preferences grid.
 *
 * Renders a (event_type × channel) matrix of toggle checkboxes.
 * The BE owns the source-of-truth (R18 — derive from DB) for
 * event_types, the channel catalog, AND the subset of channels
 * actually wired with an adapter URL. Un-registered channels render
 * as disabled toggles so the user can see WHY a channel is
 * un-toggleable (operator must wire the webhook URL first).
 *
 * R29 testid hierarchy:
 *   - `notif-pref`                                top-level container
 *   - `notif-pref-cell-{event}-{channel}-toggle`  cell checkbox
 *   - `notif-pref-row-{event}-enable-all`         row bulk-on
 *   - `notif-pref-row-{event}-disable-all`        row bulk-off
 *   - `notif-pref-column-{channel}-enable-all`    column bulk-on
 *   - `notif-pref-column-{channel}-disable-all`   column bulk-off
 *   - `notif-pref-save`                           save button
 *   - `notif-pref-discard`                        reset to last-saved
 *   - `notif-pref-dirty-indicator`                "Unsaved changes" hint
 *   - `notif-pref-save-error`                     error banner
 *   - `notif-pref-save-success`                   post-save success banner (persists until the next mutation / discard)
 *   - `notif-pref-loading` / `-error`             data-state placeholders
 *
 * R15 a11y: every checkbox has an `aria-label` ("Enable email for
 * Doc created"). The grid is wrapped in `<table>` with proper
 * `<th scope>` so screen readers announce header context per cell.
 *
 * R30 implicit: the BE scopes all reads/writes by (tenant_id,
 * user_id) — the FE never sends tenant_id, never selects a target
 * user.
 */

function cellKey(eventType: string, channel: string): string {
    return `${eventType}|${channel}`;
}

function buildCurrentMatrix(data: NotificationPreferencesResponse): Record<string, boolean> {
    const matrix: Record<string, boolean> = {};
    for (const evt of data.event_types) {
        for (const chan of data.channels) {
            // Seed every cell with the channel's default; explicit
            // preference rows override below.
            matrix[cellKey(evt, chan)] = data.defaults[chan] ?? false;
        }
    }
    for (const pref of data.preferences) {
        matrix[cellKey(pref.event_type, pref.channel)] = pref.enabled;
    }
    return matrix;
}

export function NotificationPreferencesGrid(): ReactNode {
    const qc = useQueryClient();

    const query = useQuery({
        queryKey: ['notifications', 'preferences'],
        queryFn: () => notificationsApi.loadPreferences(),
        // Preferences rarely change; the panel does not poll and
        // does not refetch on focus — Discard pulls from the cache,
        // an explicit page reload is the way to re-read the BE.
        refetchOnWindowFocus: false,
    });

    // `edits` is `null` until `query.data` arrives; the first paint
    // gates the grid on `data-state='loading'` rather than risk a
    // single-frame render where every checkbox would read unchecked
    // because `!!edits[k]` evaluates false against an empty map
    // (Copilot iter-1 #2 — initial-render flicker / flaky test
    // assertions against a momentarily-unchecked grid).
    const [edits, setEdits] = useState<Record<string, boolean> | null>(null);

    // v8.0.1 / deep-review F5 — counterfactual toggle is now a
    // per-user server-persisted preference (was browser-local
    // localStorage). Multi-device / fresh-session usage keeps the
    // user's choice. Default-true while loading so the first paint
    // matches the prior localStorage default.
    const counterfactualQuery = useQuery({
        queryKey: CHAT_PREFERENCES_QUERY_KEY,
        queryFn: () => chatPreferencesApi.load(),
        staleTime: 5 * 60_000,
        refetchOnWindowFocus: false,
    });
    const counterfactualEnabled =
        counterfactualQuery.data?.preferences.counterfactual_enabled ?? true;
    const counterfactualMut = useMutation({
        mutationFn: (next: boolean) => chatPreferencesApi.save({ counterfactual_enabled: next }),
        onSuccess: (data: ChatPreferencesResponse) => {
            qc.setQueryData(CHAT_PREFERENCES_QUERY_KEY, data);
        },
    });

    // R17 — seed the local edit cache from the server snapshot ONLY on
    // the very first load (when `edits` is still null). A naive seed
    // on every `query.data` change would clobber unsaved user edits
    // the moment TanStack Query revalidates in the background (e.g.
    // network reconnect, manual invalidation, future staleness window)
    // — Copilot iter-4 caught the clobber. Post-save sync is handled
    // explicitly inside `saveMut.onSuccess` against the canonical
    // server payload; Discard re-seeds via the `discard()` callback.
    useEffect(() => {
        if (!query.data || edits !== null) return;
        setEdits(buildCurrentMatrix(query.data));
    }, [query.data, edits]);

    const saveMut = useMutation({
        mutationFn: (rows: NotificationPreferenceRow[]) => notificationsApi.savePreferences(rows),
        onSuccess: (data) => {
            // BE returns the freshly-saved canonical snapshot. Drop it
            // into the cache AND re-seed local edits explicitly so the
            // grid converges to the saved values (turning it clean).
            qc.setQueryData(['notifications', 'preferences'], data);
            setEdits(buildCurrentMatrix(data));
        },
    });

    // The grid stays in `loading` until edits has been seeded from
    // the server payload, so the first paint never shows the empty-
    // `{}` map. `event_types` comes from a closed static enum on the
    // BE (5 values today), so there is no reachable empty state
    // (Copilot iter-2: removed dangling 'empty' branch the render
    // tree never handled).
    const dataState = query.isError
        ? 'error'
        : query.isLoading || edits === null
            ? 'loading'
            : 'ready';

    const toggleCell = (eventType: string, channel: string) => {
        const k = cellKey(eventType, channel);
        setEdits((prev) => ({ ...(prev ?? {}), [k]: !((prev ?? {})[k]) }));
    };

    const setRow = (eventType: string, enabled: boolean) => {
        if (!query.data || edits === null) return;
        const registered = new Set(query.data.registered_channels);
        const next = { ...edits };
        for (const chan of query.data.channels) {
            // Skip un-registered channels — the BE will accept the
            // value but flipping it is meaningless until the channel
            // has a URL configured.
            if (!registered.has(chan)) continue;
            next[cellKey(eventType, chan)] = enabled;
        }
        setEdits(next);
    };

    const setColumn = (channel: string, enabled: boolean) => {
        if (!query.data || edits === null) return;
        if (!query.data.registered_channels.includes(channel)) return;
        const next = { ...edits };
        for (const evt of query.data.event_types) {
            next[cellKey(evt, channel)] = enabled;
        }
        setEdits(next);
    };

    const save = () => {
        if (!query.data || edits === null) return;
        const rows: NotificationPreferenceRow[] = [];
        for (const evt of query.data.event_types) {
            for (const chan of query.data.channels) {
                rows.push({
                    event_type: evt,
                    channel: chan,
                    enabled: !!edits[cellKey(evt, chan)],
                });
            }
        }
        saveMut.mutate(rows);
    };

    const discard = () => {
        if (!query.data) return;
        setEdits(buildCurrentMatrix(query.data));
        saveMut.reset();
    };

    // First-save semantics (Copilot iter-1 #1):
    // A user who has zero `notification_preferences` rows AND who
    // accepts the seeded defaults must still be able to press Save —
    // otherwise the dispatcher (which only delivers when `enabled=true`
    // rows exist in the DB) would NEVER ship a notification to that
    // user until they manually flip something off and back on. Treat
    // the absent-rows + matching-defaults state as inherently dirty
    // so the very first Save persists the defaults verbatim.
    const dirty = useMemo(() => {
        if (!query.data || edits === null) return false;
        // First-save case: no stored prefs yet AND the user just
        // landed on the page. Save is enabled so the user can opt
        // in by clicking Save against the seeded defaults.
        if (query.data.preferences.length === 0) return true;
        const current = buildCurrentMatrix(query.data);
        for (const k of Object.keys(current)) {
            if (edits[k] !== current[k]) return true;
        }
        return false;
    }, [edits, query.data]);

    const toggleCounterfactual = () => {
        counterfactualMut.mutate(!counterfactualEnabled);
    };

    return (
        <div
            data-testid="notif-pref"
            data-state={dataState}
            aria-busy={query.isFetching}
            className="space-y-4 p-6"
        >
            <h1 className="text-2xl font-semibold">Notification preferences</h1>
            <p className="text-sm text-gray-600">
                Choose which channels receive each notification. Channels with
                no webhook URL configured by your operator render as disabled.
            </p>

            <div className="rounded border border-gray-200 bg-gray-50 p-3">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <p className="text-sm font-medium text-gray-800">Counterfactual panel in chat</p>
                        <p className="text-xs text-gray-600">
                            Show/hide “N other projects” counterfactual citations. Saved on the
                            server, follows you across devices and sessions. Default ON.
                        </p>
                    </div>
                    <label className="inline-flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={counterfactualEnabled}
                            onChange={toggleCounterfactual}
                            disabled={counterfactualQuery.isLoading || counterfactualMut.isPending}
                            data-testid="chat-counterfactual-toggle"
                            aria-label="Show counterfactual panel in chat"
                        />
                        <span>{counterfactualEnabled ? 'On' : 'Off'}</span>
                    </label>
                </div>
            </div>

            {dataState === 'loading' && (
                <p data-testid="notif-pref-loading" className="py-8 text-center text-gray-500">
                    Loading…
                </p>
            )}

            {dataState === 'error' && (
                <div data-testid="notif-pref-error" className="rounded border border-red-300 bg-red-50 p-4">
                    <p className="text-sm text-red-700">Could not load preferences.</p>
                    <button
                        type="button"
                        data-testid="notif-pref-retry"
                        onClick={() => void query.refetch()}
                        className="mt-2 text-sm text-red-700 underline"
                    >
                        Retry
                    </button>
                </div>
            )}

            {saveMut.isError && (
                <div
                    data-testid="notif-pref-save-error"
                    role="alert"
                    className="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700"
                >
                    Save failed. Please retry.
                    <button
                        type="button"
                        onClick={() => saveMut.reset()}
                        className="ml-2 underline"
                    >
                        Dismiss
                    </button>
                </div>
            )}

            {saveMut.isSuccess && !dirty && (
                <div
                    data-testid="notif-pref-save-success"
                    role="status"
                    className="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700"
                >
                    Preferences saved.
                </div>
            )}

            {dataState === 'ready' && query.data && edits && (
                <>
                    <div className="overflow-x-auto rounded border border-gray-200">
                        <table className="min-w-full border-collapse text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="border-b border-gray-200 p-2 text-left">
                                        Event
                                    </th>
                                    {query.data.channels.map((chan) => {
                                        const registered = query.data!.registered_channels.includes(chan);
                                        return (
                                            <th
                                                key={chan}
                                                scope="col"
                                                className="border-b border-gray-200 p-2 text-center"
                                            >
                                                <div className="flex flex-col items-center gap-1">
                                                    <span className={registered ? '' : 'text-gray-400'}>
                                                        {CHANNEL_LABELS[chan] ?? chan}
                                                        {!registered && (
                                                            <span className="ml-1 text-xs">(not configured)</span>
                                                        )}
                                                    </span>
                                                    {registered && (
                                                        <div className="flex gap-1">
                                                            <button
                                                                type="button"
                                                                data-testid={`notif-pref-column-${chan}-enable-all`}
                                                                aria-label={`Enable all ${CHANNEL_LABELS[chan] ?? chan} notifications`}
                                                                onClick={() => setColumn(chan, true)}
                                                                disabled={saveMut.isPending}
                                                                className="text-xs text-blue-600 hover:underline disabled:text-gray-400"
                                                            >
                                                                On
                                                            </button>
                                                            <button
                                                                type="button"
                                                                data-testid={`notif-pref-column-${chan}-disable-all`}
                                                                aria-label={`Disable all ${CHANNEL_LABELS[chan] ?? chan} notifications`}
                                                                onClick={() => setColumn(chan, false)}
                                                                disabled={saveMut.isPending}
                                                                className="text-xs text-gray-600 hover:underline disabled:text-gray-400"
                                                            >
                                                                Off
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            </th>
                                        );
                                    })}
                                    <th scope="col" className="border-b border-gray-200 p-2 text-center">
                                        Row actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {query.data.event_types.map((evt) => (
                                    <tr key={evt} className="even:bg-gray-50">
                                        <th
                                            scope="row"
                                            className="border-b border-gray-100 p-2 text-left font-normal"
                                        >
                                            {EVENT_TYPE_LABELS[evt] ?? evt}
                                        </th>
                                        {query.data!.channels.map((chan) => {
                                            const registered = query.data!.registered_channels.includes(chan);
                                            const k = cellKey(evt, chan);
                                            const checked = !!edits[k];
                                            const label = `Enable ${CHANNEL_LABELS[chan] ?? chan} for ${EVENT_TYPE_LABELS[evt] ?? evt}`;
                                            return (
                                                <td
                                                    key={chan}
                                                    className="border-b border-gray-100 p-2 text-center"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        data-testid={`notif-pref-cell-${evt}-${chan}-toggle`}
                                                        aria-label={label}
                                                        checked={checked}
                                                        // Copilot iter-5: when the channel was previously
                                                        // enabled (DB row carries `enabled=true`) but the
                                                        // operator has since de-registered the adapter URL,
                                                        // the user still needs to be able to UNCHECK so
                                                        // future deliveries stop. Only block the
                                                        // enable transition; allow disable.
                                                        disabled={saveMut.isPending || (!registered && !checked)}
                                                        onChange={() => toggleCell(evt, chan)}
                                                        className="h-4 w-4"
                                                    />
                                                </td>
                                            );
                                        })}
                                        <td className="border-b border-gray-100 p-2 text-center">
                                            <div className="flex justify-center gap-2">
                                                <button
                                                    type="button"
                                                    data-testid={`notif-pref-row-${evt}-enable-all`}
                                                    aria-label={`Enable all channels for ${EVENT_TYPE_LABELS[evt] ?? evt}`}
                                                    onClick={() => setRow(evt, true)}
                                                    disabled={saveMut.isPending}
                                                    className="text-xs text-blue-600 hover:underline disabled:text-gray-400"
                                                >
                                                    All on
                                                </button>
                                                <button
                                                    type="button"
                                                    data-testid={`notif-pref-row-${evt}-disable-all`}
                                                    aria-label={`Disable all channels for ${EVENT_TYPE_LABELS[evt] ?? evt}`}
                                                    onClick={() => setRow(evt, false)}
                                                    disabled={saveMut.isPending}
                                                    className="text-xs text-gray-600 hover:underline disabled:text-gray-400"
                                                >
                                                    All off
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex items-center justify-between">
                        <div className="text-sm text-gray-500">
                            {dirty && (
                                <span data-testid="notif-pref-dirty-indicator">
                                    Unsaved changes
                                </span>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                data-testid="notif-pref-discard"
                                onClick={discard}
                                disabled={!dirty || saveMut.isPending}
                                className="rounded border border-gray-300 px-3 py-1 text-sm disabled:text-gray-400"
                            >
                                Discard
                            </button>
                            <button
                                type="button"
                                data-testid="notif-pref-save"
                                onClick={save}
                                disabled={!dirty || saveMut.isPending}
                                className="rounded bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-700 disabled:bg-gray-300"
                            >
                                {saveMut.isPending ? 'Saving…' : 'Save'}
                            </button>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
