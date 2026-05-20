import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    notificationsApi,
    type NotificationPreferenceRow,
    type NotificationTenantDefaultsResponse,
} from './notifications.api';
import { useAuthStore } from '../../lib/auth-store';
import { EVENT_TYPE_LABELS, CHANNEL_LABELS } from './labels';

/**
 * v8.0/W2.3 — /app/admin/notifications/defaults grid.
 *
 * Per-tenant baseline (event_type × channel) matrix that
 * `NotificationPreferencesInitializer` applies to brand-new users.
 * Layout mirrors `NotificationPreferencesGrid` (W2.2 user-side); the
 * only difference is the row shape — admin defaults don't carry
 * `user_id`. `save()` posts the FULL matrix (every event × channel
 * cell, mirroring the user grid) so the BE can dedup with the same
 * last-wins semantics. Unchanged cells DO re-write `updated_at` on
 * every Save (the composite-unique upsert touches every row in the
 * payload), but the `enabled` business value is idempotent — the
 * resulting state matches the canonical payload byte-for-byte.
 * Sparse-delta change-tracking was considered and rejected: the
 * matrix is 5x6 = 30 rows max, so updating the whole grid on every
 * Save is cheaper than the FE-side state machinery it would replace.
 *
 * RBAC: read is open to admin + super-admin (route ACL on the BE);
 * the PUT path is rejected with 403 for non-super-admin. The FE
 * doesn't gate the UI further — the BE response is the source of
 * truth.
 *
 * R29 testid hierarchy (mirrors W2.2 with `notif-defaults-*` prefix):
 *   - `notif-defaults`                                top-level container
 *   - `notif-defaults-cell-{event}-{channel}-toggle`  cell checkbox
 *   - `notif-defaults-row-{event}-enable-all`         row bulk-on
 *   - `notif-defaults-row-{event}-disable-all`        row bulk-off
 *   - `notif-defaults-column-{channel}-enable-all`    column bulk-on
 *   - `notif-defaults-column-{channel}-disable-all`   column bulk-off
 *   - `notif-defaults-save` / `notif-defaults-discard`
 *   - `notif-defaults-dirty-indicator`
 *   - `notif-defaults-save-error` / `notif-defaults-save-success`
 *   - `notif-defaults-loading` / `notif-defaults-error`
 *
 * R30 implicit: BE scopes the read + write by the active
 * `TenantContext`; the FE never sends a tenant_id and never selects
 * a tenant.
 */

function cellKey(eventType: string, channel: string): string {
    return `${eventType}|${channel}`;
}

function buildCurrentMatrix(data: NotificationTenantDefaultsResponse): Record<string, boolean> {
    const matrix: Record<string, boolean> = {};
    for (const evt of data.event_types) {
        for (const chan of data.channels) {
            // Seed every cell with the platform default; tenant
            // overrides win where present.
            matrix[cellKey(evt, chan)] = data.platform_defaults[chan] ?? false;
        }
    }
    for (const row of data.defaults) {
        matrix[cellKey(row.event_type, row.channel)] = row.enabled;
    }
    return matrix;
}

export function AdminNotificationDefaultsGrid(): ReactNode {
    const qc = useQueryClient();

    // BE rejects PUT for non-super-admin with 403. Mirror that in the
    // UI so an `admin` (allowed to VIEW the route via the route ACL)
    // doesn't see "Unsaved changes" + a Save button that's
    // deterministically going to 403 (Copilot iter-5 #L178). The
    // route guard already ensures `admin` is the minimum role here;
    // `canMutate` narrows further to `super-admin`.
    const roles = useAuthStore((s) => s.roles);
    const canMutate = roles.includes('super-admin');

    const query = useQuery({
        queryKey: ['notifications', 'tenant-defaults'],
        queryFn: () => notificationsApi.loadTenantDefaults(),
        refetchOnWindowFocus: false,
    });

    const [edits, setEdits] = useState<Record<string, boolean> | null>(null);

    // R17 — seed once on first arrival; do NOT re-seed on every
    // `query.data` change, otherwise a background revalidation would
    // clobber unsaved edits. Post-save sync is explicit inside
    // `saveMut.onSuccess`.
    useEffect(() => {
        if (!query.data || edits !== null) return;
        setEdits(buildCurrentMatrix(query.data));
    }, [query.data, edits]);

    const saveMut = useMutation({
        mutationFn: (rows: NotificationPreferenceRow[]) =>
            notificationsApi.saveTenantDefaults(rows),
        onSuccess: (data) => {
            qc.setQueryData(['notifications', 'tenant-defaults'], data);
            setEdits(buildCurrentMatrix(data));
        },
    });

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

    // Defaults are typically empty until a super-admin edits them.
    // First-save semantics mirror W2.2: an empty `defaults` array
    // counts as dirty so the SUPER-ADMIN operator can persist the
    // seeded platform defaults verbatim. For read-only viewers
    // (`admin` without super-admin), `canMutate=false` short-circuits
    // BEFORE the dirty calculation so the grid never advertises a
    // Save that the BE would reject with 403 (Copilot iter-5 #L178).
    const dirty = useMemo(() => {
        if (!canMutate) return false;
        if (!query.data || edits === null) return false;
        if (query.data.defaults.length === 0) return true;
        const current = buildCurrentMatrix(query.data);
        for (const k of Object.keys(current)) {
            if (edits[k] !== current[k]) return true;
        }
        return false;
    }, [edits, query.data, canMutate]);

    return (
        <div
            data-testid="notif-defaults"
            data-state={dataState}
            aria-busy={query.isFetching}
            className="space-y-4 p-6"
        >
            <h1 className="text-2xl font-semibold">Tenant notification defaults</h1>
            <p className="text-sm text-gray-600">
                These defaults apply to brand-new users when they are
                created. Existing users' preferences are unaffected.
                Channels with no webhook URL configured by your operator
                render as disabled.
            </p>

            {dataState === 'loading' && (
                <p data-testid="notif-defaults-loading" className="py-8 text-center text-gray-500">
                    Loading…
                </p>
            )}

            {dataState === 'error' && (
                <div data-testid="notif-defaults-error" className="rounded border border-red-300 bg-red-50 p-4">
                    <p className="text-sm text-red-700">Could not load tenant defaults.</p>
                    <button
                        type="button"
                        data-testid="notif-defaults-retry"
                        onClick={() => void query.refetch()}
                        className="mt-2 text-sm text-red-700 underline"
                    >
                        Retry
                    </button>
                </div>
            )}

            {!canMutate && dataState === 'ready' && (
                <div
                    data-testid="notif-defaults-readonly-banner"
                    role="status"
                    className="rounded border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700"
                >
                    Read-only view. Only super-admins can edit tenant defaults.
                </div>
            )}

            {saveMut.isError && (
                <div
                    data-testid="notif-defaults-save-error"
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
                    data-testid="notif-defaults-save-success"
                    role="status"
                    className="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700"
                >
                    Tenant defaults saved.
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
                                                                data-testid={`notif-defaults-column-${chan}-enable-all`}
                                                                aria-label={`Enable all ${CHANNEL_LABELS[chan] ?? chan} defaults`}
                                                                onClick={() => setColumn(chan, true)}
                                                                disabled={saveMut.isPending || !canMutate}
                                                                className="text-xs text-blue-600 hover:underline disabled:text-gray-400"
                                                            >
                                                                On
                                                            </button>
                                                            <button
                                                                type="button"
                                                                data-testid={`notif-defaults-column-${chan}-disable-all`}
                                                                aria-label={`Disable all ${CHANNEL_LABELS[chan] ?? chan} defaults`}
                                                                onClick={() => setColumn(chan, false)}
                                                                disabled={saveMut.isPending || !canMutate}
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
                                            const label = `Default ${CHANNEL_LABELS[chan] ?? chan} for ${EVENT_TYPE_LABELS[evt] ?? evt}`;
                                            return (
                                                <td
                                                    key={chan}
                                                    className="border-b border-gray-100 p-2 text-center"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        data-testid={`notif-defaults-cell-${evt}-${chan}-toggle`}
                                                        aria-label={label}
                                                        checked={checked}
                                                        // Same uncheck-allowed-when-unregistered semantics as
                                                        // the user grid: super-admin can disable a previously-
                                                        // enabled default even after the operator removes the
                                                        // adapter URL, so future user creations stop getting
                                                        // seeded with the orphan enabled-bit.
                                                        disabled={saveMut.isPending || !canMutate || (!registered && !checked)}
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
                                                    data-testid={`notif-defaults-row-${evt}-enable-all`}
                                                    aria-label={`Enable all default channels for ${EVENT_TYPE_LABELS[evt] ?? evt}`}
                                                    onClick={() => setRow(evt, true)}
                                                    disabled={saveMut.isPending || !canMutate}
                                                    className="text-xs text-blue-600 hover:underline disabled:text-gray-400"
                                                >
                                                    All on
                                                </button>
                                                <button
                                                    type="button"
                                                    data-testid={`notif-defaults-row-${evt}-disable-all`}
                                                    aria-label={`Disable all default channels for ${EVENT_TYPE_LABELS[evt] ?? evt}`}
                                                    onClick={() => setRow(evt, false)}
                                                    disabled={saveMut.isPending || !canMutate}
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
                                <span data-testid="notif-defaults-dirty-indicator">
                                    Unsaved changes
                                </span>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                data-testid="notif-defaults-discard"
                                onClick={discard}
                                disabled={!dirty || saveMut.isPending}
                                className="rounded border border-gray-300 px-3 py-1 text-sm disabled:text-gray-400"
                            >
                                Discard
                            </button>
                            <button
                                type="button"
                                data-testid="notif-defaults-save"
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
