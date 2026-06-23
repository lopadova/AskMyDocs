import { useEffect, useState } from 'react';
import { AdminShell } from '../shell/AdminShell';
import { flattenLaravelError, parseLaravelError } from '../../../lib/laravel-errors';
import { useAppSettings, useSetAppSetting } from './app-settings-hooks';
import { WILDCARD, type AppSettingDto } from './app-settings.api';

/*
 * v8.22 (Ciclo 3) — "Configuration" admin screen (super-admin).
 *
 * Edits the curated runtime-governable settings (AppSettingRegistry) per
 * (tenant, project) without a deploy, over the AppSettingsResolver HTTP
 * surface. One row per key: enum→select, int→number, bool→select; deploy-only
 * keys are read-only; a tenant-scoped key is read-only while a project scope
 * is active (it can only vary tenant-wide).
 *
 * R11 testid/ARIA/state contract, R14 explicit empty/loading/error + inline
 * 422, R15 labelled controls.
 */

function sourceBadge(source: string): { bg: string; border: string; fg: string; text: string } {
    switch (source) {
        case 'project':
            return { bg: 'rgba(99,102,241,0.16)', border: 'rgba(99,102,241,0.45)', fg: '#a5b4fc', text: 'project override' };
        case 'tenant':
            return { bg: 'rgba(16,185,129,0.16)', border: 'rgba(16,185,129,0.45)', fg: '#34d399', text: 'tenant override' };
        default:
            return { bg: 'rgba(148,163,184,0.12)', border: 'rgba(148,163,184,0.30)', fg: '#94a3b8', text: 'config default' };
    }
}

function SettingRow({ setting, projectKey }: { setting: AppSettingDto; projectKey: string }) {
    const mutation = useSetAppSetting(projectKey);
    const [draft, setDraft] = useState<string>(setting.value === null ? '' : String(setting.value));

    // Re-sync the draft whenever the server value changes (after a save/refetch)
    // so the editor never shows a stale value (R17 — sync cached state).
    useEffect(() => {
        setDraft(setting.value === null ? '' : String(setting.value));
    }, [setting.value]);

    const scopedToProject = projectKey !== WILDCARD;
    // A tenant-scoped key cannot be overridden per project — read-only there.
    const readOnly = setting.deploy_only || (scopedToProject && setting.scope !== 'both');
    const dirty = draft !== (setting.value === null ? '' : String(setting.value));
    const badge = sourceBadge(setting.source);
    // Reset clears the override AT THE CURRENT SCOPE only. A 'tenant' source seen
    // while a project scope is active is INHERITED (lives at '*'), so Reset here
    // could not clear it — don't offer an action that wouldn't work.
    const overrideAtScope = scopedToProject ? setting.source === 'project' : setting.source === 'tenant';

    const submit = () => {
        const raw =
            setting.type === 'bool'
                ? draft === 'true'
                : setting.type === 'int'
                  ? draft.trim() === ''
                      ? null
                      : Number(draft)
                  : draft;
        mutation.mutate({ key: setting.key, value: raw });
    };

    const clearOverride = () => mutation.mutate({ key: setting.key, value: null });

    const error = mutation.isError ? flattenLaravelError(parseLaravelError(mutation.error)) : null;

    return (
        <tr data-testid={`app-setting-row-${setting.key}`} data-source={setting.source}>
            <td style={tdStyle}>
                <div style={{ fontSize: 13, color: 'var(--fg-0)' }}>{setting.label}</div>
                <div style={{ fontSize: 11, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>{setting.key}</div>
                {readOnly && setting.deploy_only && (
                    <span data-testid={`app-setting-${setting.key}-deploy-only`} style={pillStyle('rgba(148,163,184,0.12)', 'rgba(148,163,184,0.30)', '#94a3b8')}>
                        deploy-managed
                    </span>
                )}
                {readOnly && !setting.deploy_only && (
                    <span data-testid={`app-setting-${setting.key}-tenant-only`} style={pillStyle('rgba(148,163,184,0.12)', 'rgba(148,163,184,0.30)', '#94a3b8')}>
                        tenant-wide only
                    </span>
                )}
            </td>

            <td style={tdStyle}>
                <span data-testid={`app-setting-${setting.key}-source`} style={pillStyle(badge.bg, badge.border, badge.fg)}>
                    {badge.text}
                </span>
            </td>

            <td style={tdStyle}>
                {readOnly ? (
                    <span data-testid={`app-setting-${setting.key}-value`} style={{ fontFamily: 'var(--font-mono)', fontSize: 12.5, color: 'var(--fg-1)' }}>
                        {setting.value === null ? '—' : String(setting.value)}
                    </span>
                ) : setting.type === 'enum' ? (
                    <select
                        aria-label={`${setting.label} value`}
                        data-testid={`app-setting-${setting.key}-input`}
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        style={inputStyle}
                    >
                        {(setting.enum ?? []).map((opt) => (
                            <option key={opt} value={opt}>
                                {opt}
                            </option>
                        ))}
                    </select>
                ) : setting.type === 'bool' ? (
                    <select
                        aria-label={`${setting.label} value`}
                        data-testid={`app-setting-${setting.key}-input`}
                        value={draft === 'true' ? 'true' : 'false'}
                        onChange={(e) => setDraft(e.target.value)}
                        style={inputStyle}
                    >
                        <option value="true">true</option>
                        <option value="false">false</option>
                    </select>
                ) : (
                    <input
                        type={setting.type === 'int' ? 'number' : 'text'}
                        aria-label={`${setting.label} value`}
                        data-testid={`app-setting-${setting.key}-input`}
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        style={{ ...inputStyle, width: 120 }}
                    />
                )}
            </td>

            <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>
                {!readOnly && (
                    <>
                        <button
                            type="button"
                            data-testid={`app-setting-${setting.key}-save`}
                            className="focus-ring"
                            disabled={!dirty || mutation.isPending}
                            onClick={submit}
                            style={saveStyle(!dirty || mutation.isPending)}
                        >
                            {mutation.isPending ? 'Saving…' : 'Save'}
                        </button>
                        {overrideAtScope && (
                            <button
                                type="button"
                                data-testid={`app-setting-${setting.key}-clear`}
                                className="focus-ring"
                                disabled={mutation.isPending}
                                onClick={clearOverride}
                                style={clearStyle}
                            >
                                Reset
                            </button>
                        )}
                    </>
                )}
                {error && (
                    <div data-testid={`app-setting-${setting.key}-error`} role="alert" style={{ color: '#fca5a5', fontSize: 11.5, marginTop: 4 }}>
                        {error}
                    </div>
                )}
            </td>
        </tr>
    );
}

export function AppSettingsView() {
    const [scopeInput, setScopeInput] = useState<string>('');
    const projectKey = scopeInput.trim() === '' ? WILDCARD : scopeInput.trim();
    const query = useAppSettings(projectKey);

    const state: 'loading' | 'ready' | 'error' = query.isLoading
        ? 'loading'
        : query.isError
          ? 'error'
          : 'ready';

    return (
        <AdminShell section="app-settings">
            <div
                data-testid="admin-app-settings"
                data-state={state}
                aria-busy={state === 'loading'}
                style={{ display: 'flex', flexDirection: 'column', gap: 16 }}
            >
                <div>
                    <h1 style={{ fontSize: 20, fontWeight: 600, margin: '0 0 2px', letterSpacing: '-0.02em', color: 'var(--fg-0)' }}>
                        Configuration
                    </h1>
                    <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
                        Governable runtime settings (AI provider, connector sync cadence). Changes take
                        effect without a deploy. Deploy-managed knobs are read-only.
                    </p>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                    <label htmlFor="app-settings-scope" style={{ fontSize: 12.5, color: 'var(--fg-2)' }}>
                        Project scope
                    </label>
                    <input
                        id="app-settings-scope"
                        data-testid="app-settings-scope"
                        value={scopeInput}
                        placeholder="* (tenant-wide)"
                        onChange={(e) => setScopeInput(e.target.value)}
                        style={{ ...inputStyle, width: 220 }}
                    />
                    <span style={{ fontSize: 11.5, color: 'var(--fg-3)' }}>
                        Leave blank for the tenant-wide default; enter a project key to scope per-project keys.
                    </span>
                </div>

                {state === 'loading' && (
                    <div data-testid="admin-app-settings-loading" role="status" aria-busy="true" style={panelStyle}>
                        Loading settings…
                    </div>
                )}
                {state === 'error' && (
                    <div data-testid="admin-app-settings-error" role="alert" style={errorStyle}>
                        Could not load settings.{' '}
                        <button type="button" data-testid="admin-app-settings-retry" className="focus-ring" onClick={() => query.refetch()} style={retryStyle}>
                            Retry
                        </button>
                    </div>
                )}
                {state === 'ready' && (query.data ?? []).length === 0 && (
                    <div data-testid="admin-app-settings-empty" role="status" style={panelStyle}>
                        No governable settings are registered.
                    </div>
                )}
                {state === 'ready' && (query.data ?? []).length > 0 && (
                    <table data-testid="admin-app-settings-table" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
                        <thead>
                            <tr style={{ textAlign: 'left', color: 'var(--fg-3)' }}>
                                <th style={thStyle}>Setting</th>
                                <th style={thStyle}>Source</th>
                                <th style={thStyle}>Value</th>
                                <th style={thStyle}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(query.data ?? []).map((s) => (
                                <SettingRow key={s.key} setting={s} projectKey={projectKey} />
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AdminShell>
    );
}

function pillStyle(bg: string, border: string, fg: string): React.CSSProperties {
    return {
        display: 'inline-block',
        marginTop: 4,
        padding: '2px 8px',
        borderRadius: 99,
        fontSize: 11,
        background: bg,
        border: `1px solid ${border}`,
        color: fg,
    };
}

const panelStyle: React.CSSProperties = {
    padding: 24,
    textAlign: 'center',
    color: 'var(--fg-3)',
    border: '1px dashed var(--hairline)',
    borderRadius: 10,
};

const errorStyle: React.CSSProperties = {
    padding: 16,
    background: 'rgba(239, 68, 68, 0.08)',
    border: '1px solid rgba(239, 68, 68, 0.30)',
    borderRadius: 10,
    color: '#fca5a5',
    fontSize: 13,
};

const retryStyle: React.CSSProperties = {
    marginLeft: 8,
    padding: '4px 10px',
    fontSize: 12,
    background: 'transparent',
    color: '#fca5a5',
    border: '1px solid rgba(239, 68, 68, 0.45)',
    borderRadius: 6,
    cursor: 'pointer',
};

const inputStyle: React.CSSProperties = {
    padding: '5px 8px',
    borderRadius: 6,
    border: '1px solid var(--hairline)',
    background: 'var(--bg-2)',
    color: 'var(--fg-0)',
    fontSize: 12.5,
};

function saveStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '4px 12px',
        fontSize: 12,
        background: disabled ? 'var(--bg-2)' : 'var(--accent, #6366f1)',
        color: disabled ? 'var(--fg-3)' : '#fff',
        border: '1px solid var(--hairline)',
        borderRadius: 6,
        cursor: disabled ? 'not-allowed' : 'pointer',
    };
}

const clearStyle: React.CSSProperties = {
    marginLeft: 6,
    padding: '4px 10px',
    fontSize: 12,
    background: 'transparent',
    color: 'var(--fg-2)',
    border: '1px solid var(--hairline)',
    borderRadius: 6,
    cursor: 'pointer',
};

const thStyle: React.CSSProperties = { padding: '6px 8px', borderBottom: '1px solid var(--hairline)', fontWeight: 500 };
const tdStyle: React.CSSProperties = { padding: '8px 8px', borderBottom: '1px solid var(--hairline)', color: 'var(--fg-1)', verticalAlign: 'top' };
