import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    getAutoWikiSettings,
    upsertAutoWikiSetting,
    FLAG_KEYS,
    FLAG_LABELS,
    type AutoWikiFlagKey,
    type AutoWikiSettingEntry,
} from './autowiki-settings.api';

/**
 * v8.11/P10 — per-(tenant, project) Auto-Wiki gate (auto-build).
 *
 * Each project (plus a tenant-wide "All projects" row) exposes three tri-state
 * controls — Inherit / On / Off — for the auto-build flags. A null override
 * INHERITS the next level up (project → tenant '*' → config), shown as the
 * resolved "effective" value beside each control (R43: both states reachable).
 *
 * R11 testids · R14 distinct loading/empty/error states + loud mutation errors ·
 * R15 every control has an accessible name.
 */
export function AutoWikiSettingsView(): ReactNode {
    const qc = useQueryClient();
    const [saveError, setSaveError] = useState<string | null>(null);

    const query = useQuery({
        queryKey: ['admin-autowiki-settings'],
        queryFn: getAutoWikiSettings,
        staleTime: 15_000,
    });

    const mutation = useMutation({
        mutationFn: (vars: { projectKey: string; flag: AutoWikiFlagKey; value: boolean | null }) =>
            upsertAutoWikiSetting(vars.projectKey, vars.flag, vars.value),
        onSuccess: () => {
            setSaveError(null);
            qc.invalidateQueries({ queryKey: ['admin-autowiki-settings'] });
        },
        onError: (err: unknown) => {
            setSaveError(err instanceof Error ? err.message : 'Could not save the setting.');
        },
    });

    const setFlag = (entry: AutoWikiSettingEntry, flag: AutoWikiFlagKey, value: boolean | null): void =>
        mutation.mutate({ projectKey: entry.project_key, flag, value });

    const data = query.data;
    const rootState = query.isLoading ? 'loading' : query.isError ? 'error' : 'ready';

    return (
        <div
            data-testid="admin-autowiki-settings-view"
            data-state={rootState}
            aria-busy={query.isLoading || query.isFetching || mutation.isPending}
            style={{ padding: 24 }}
        >
            <header style={{ marginBottom: 8 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Auto-Wiki Settings</h1>
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 11.5, maxWidth: 680 }}>
                Turn the Auto-Wiki compiler on or off per project — independently for the canonical and
                non-canonical splits. <strong>Inherit</strong> falls back to the tenant-wide row, then the
                server default. Turning <strong>Auto-build</strong> off nets the dependent knobs off too. The
                resolved value is shown beside each control.
            </p>

            {saveError && (
                <p data-testid="admin-autowiki-settings-save-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, marginBottom: 8 }}>
                    {saveError}
                </p>
            )}

            {query.isLoading && (
                <p data-testid="admin-autowiki-settings-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>Loading…</p>
            )}
            {!query.isLoading && query.isError && (
                <p data-testid="admin-autowiki-settings-error" data-state="error" role="alert" style={{ color: 'var(--err)', padding: 24, textAlign: 'center', border: '1px dashed var(--err)', borderRadius: 8 }}>
                    Failed to load Auto-Wiki settings. {query.error instanceof Error ? query.error.message : ''}
                </p>
            )}

            {data && (
                <div data-testid="admin-autowiki-settings-list" data-state="ready" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                    <SettingRow entry={data.wildcard} label="All projects (tenant default)" busy={mutation.isPending} onSet={setFlag} />
                    {data.projects.length === 0 && (
                        <p data-testid="admin-autowiki-settings-empty" data-state="empty" style={{ color: 'var(--fg-3)', padding: 16, textAlign: 'center', border: '1px dashed var(--panel-border)', borderRadius: 8 }}>
                            No projects yet — only the tenant-wide default applies.
                        </p>
                    )}
                    {data.projects.map((entry) => (
                        <SettingRow key={entry.project_key} entry={entry} label={entry.project_key} busy={mutation.isPending} onSet={setFlag} />
                    ))}
                </div>
            )}
        </div>
    );
}

function SettingRow({
    entry, label, busy, onSet,
}: {
    entry: AutoWikiSettingEntry;
    label: string;
    busy: boolean;
    onSet: (entry: AutoWikiSettingEntry, flag: AutoWikiFlagKey, value: boolean | null) => void;
}): ReactNode {
    return (
        <div
            data-testid={`admin-autowiki-setting-${entry.project_key}`}
            style={{
                border: '1px solid var(--panel-border, rgba(255,255,255,.1))',
                borderRadius: 8, padding: '10px 14px',
                display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 16,
            }}
        >
            <strong style={{ fontSize: 13, color: 'var(--fg-0)', minWidth: 180 }}>{label}</strong>
            {FLAG_KEYS.map((flag) => (
                <FlagControl key={flag} entry={entry} flag={flag} busy={busy} onSet={onSet} />
            ))}
        </div>
    );
}

function FlagControl({
    entry, flag, busy, onSet,
}: {
    entry: AutoWikiSettingEntry;
    flag: AutoWikiFlagKey;
    busy: boolean;
    onSet: (entry: AutoWikiSettingEntry, flag: AutoWikiFlagKey, value: boolean | null) => void;
}): ReactNode {
    const override = entry.override ? entry.override[flag] : null;
    const current = override === null || override === undefined ? 'inherit' : override ? 'on' : 'off';
    const id = `admin-autowiki-setting-${entry.project_key}-${flag}`;

    return (
        <label style={{ display: 'flex', flexDirection: 'column', gap: 2, fontSize: 10.5, color: 'var(--fg-3)' }}>
            <span>{FLAG_LABELS[flag]}</span>
            <select
                id={id}
                data-testid={id}
                aria-label={`${FLAG_LABELS[flag]} for ${entry.project_key}`}
                value={current}
                disabled={busy}
                onChange={(e) => {
                    const v = e.target.value;
                    onSet(entry, flag, v === 'inherit' ? null : v === 'on');
                }}
                style={{ fontSize: 11.5, padding: '2px 4px' }}
            >
                <option value="inherit">Inherit</option>
                <option value="on">On</option>
                <option value="off">Off</option>
            </select>
            <span data-testid={`${id}-effective`} style={{ color: entry.effective[flag] ? 'var(--ok, #3fb950)' : 'var(--fg-3)' }}>
                → {entry.effective[flag] ? 'on' : 'off'}
            </span>
        </label>
    );
}
