import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import type { ConnectorInstallationDto, CredentialFieldSchema } from './connectors.api';
import { useInstallationFolders } from './connectors-hooks';
import {
    asStringList,
    boxStyle,
    buildSettingsPayload,
    buttonStyle,
    FieldRow,
    groupFields,
    inputStyle,
    isFieldVisible,
    seedValue,
    slug,
} from './settings-fields';

/*
 * v8.31 — the redesigned "Sync settings" tab of the connector Edit modal (design
 * handoff "Config Modals"). An OPINIONATED presentation of the same schema the
 * generic ConnectionSettingsForm renders, for folder-shaped connectors (IMAP):
 *
 *   - Folders: a per-folder tri-state Auto / Sync / Skip segmented control, mapped
 *     onto the schema's `folders.include` (= Sync) + `folders.exclude` (= Skip)
 *     multiselects. "Auto" = in neither list (follow the source default). A live
 *     folder list drives the rows; a saved-but-vanished folder stays visible +
 *     flagged "not on server".
 *   - Sync window: the `date_window_days` number.
 *   - Scope: the `Scope`-group checkboxes rendered as toggle switches.
 *   - Every OTHER schema group (Content / Filtering / Attachments …) is preserved
 *     below via the shared generic FieldRow, so nothing the mockup omits is lost.
 *
 * Serialises through the SAME buildSettingsPayload as the generic form, so the
 * PATCH shape is identical. When the schema is NOT folder-shaped the caller falls
 * back to ConnectionSettingsForm — this component assumes at least the folder
 * fields exist.
 *
 * R11/R29 testids `connector-{key}-settings-*`; R15 every control is labelled +
 * keyboard-reachable, scope toggles are role=switch; R14 the folder fetch
 * loading/error/ready is observable via `data-state`.
 */

export interface SyncSettingsFormProps {
    connectorKey: string;
    account: ConnectorInstallationDto;
    onSubmit: (settings: Record<string, unknown>) => void;
    onClose: () => void;
    submitError?: string | null;
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
    /** Omit the form's own footer (the host tabbed modal owns it); `formId` lets
     *  the external Save submit this form. */
    footerless?: boolean;
    formId?: string;
}

const INCLUDE = 'folders.include';
const EXCLUDE = 'folders.exclude';
const WINDOW = 'date_window_days';

type Rule = 'auto' | 'sync' | 'skip';

const RULE_META: Record<Rule, { label: string; fg: string; bg: string }> = {
    auto: { label: 'Auto', fg: 'var(--fg-1)', bg: 'var(--bg-4, #26282d)' },
    sync: { label: 'Sync', fg: '#34d399', bg: 'rgba(52,211,153,.2)' },
    skip: { label: 'Skip', fg: 'var(--err, #f87171)', bg: 'rgba(248,113,113,.2)' },
};

export function SyncSettingsForm({
    connectorKey,
    account,
    onSubmit,
    onClose,
    submitError,
    fieldErrors,
    isSubmitting,
    footerless = false,
    formId,
}: SyncSettingsFormProps): ReactNode {
    const schema = account.connection_settings_schema ?? [];
    const needsFolders = schema.some((f) => f.discovery === 'folders');
    const foldersQuery = useInstallationFolders(account.id, needsFolders);

    const [values, setValues] = useState<Record<string, unknown>>(() => {
        const seed: Record<string, unknown> = {};
        for (const f of schema) seed[f.name] = seedValue(f, account.settings ?? {});
        return seed;
    });
    const [folderQuery, setFolderQuery] = useState('');

    useEffect(() => {
        const onKey = (e: globalThis.KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const windowField = schema.find((f) => f.name === WINDOW);
    const scopeFields = schema.filter((f) => f.group === 'Scope' && f.type === 'checkbox');
    // Groups the opinionated sections own — everything else renders generically.
    const recognized = new Set<string>([INCLUDE, EXCLUDE, WINDOW, ...scopeFields.map((f) => f.name)]);
    const remainingGroups = useMemo(
        () => groupFields(schema.filter((f) => !recognized.has(f.name))),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [schema],
    );

    const live = foldersQuery.data ?? [];
    const fetchState: 'loading' | 'error' | 'ready' = foldersQuery.isLoading
        ? 'loading'
        : foldersQuery.isError
          ? 'error'
          : 'ready';
    const formState = needsFolders ? fetchState : 'ready';

    const setValue = (name: string, v: unknown) => setValues((cur) => ({ ...cur, [name]: v }));

    const include = asStringList(values[INCLUDE]);
    const exclude = asStringList(values[EXCLUDE]);
    const includeSet = useMemo(() => new Set(include), [include]);
    const excludeSet = useMemo(() => new Set(exclude), [exclude]);
    const liveSet = useMemo(() => new Set(live), [live]);

    const allFolders = useMemo(
        () => Array.from(new Set([...live, ...include, ...exclude])).sort((a, b) => a.localeCompare(b)),
        [live, include, exclude],
    );

    const ruleOf = (folder: string): Rule =>
        includeSet.has(folder) ? 'sync' : excludeSet.has(folder) ? 'skip' : 'auto';

    const setRule = (folder: string, rule: Rule) => {
        setValues((cur) => {
            const inc = asStringList(cur[INCLUDE]).filter((f) => f !== folder);
            const exc = asStringList(cur[EXCLUDE]).filter((f) => f !== folder);
            if (rule === 'sync') inc.push(folder);
            if (rule === 'skip') exc.push(folder);
            return { ...cur, [INCLUDE]: inc, [EXCLUDE]: exc };
        });
    };

    const resetAllAuto = () => setValues((cur) => ({ ...cur, [INCLUDE]: [], [EXCLUDE]: [] }));

    const nSync = include.length;
    const nSkip = exclude.length;
    const nAuto = Math.max(0, allFolders.length - nSync - nSkip);
    const folderSummary = `${nSync} sync · ${nSkip} skip · ${nAuto} auto`;

    const fq = folderQuery.trim().toLowerCase();
    const shownFolders = fq ? allFolders.filter((f) => f.toLowerCase().includes(fq)) : allFolders;

    const errorFor = (name: string): string | undefined => {
        if (!fieldErrors) return undefined;
        const exact = fieldErrors[`settings.${name}`];
        if (exact) return exact;
        const prefix = `settings.${name}.`;
        for (const [key, msg] of Object.entries(fieldErrors)) {
            if (key.startsWith(prefix)) return msg;
        }
        return undefined;
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        onSubmit(buildSettingsPayload(schema, values));
    };

    return (
        <form
            id={formId}
            aria-busy={isSubmitting}
            data-testid={`connector-${connectorKey}-settings-form`}
            data-state={formState}
            onSubmit={handleSubmit}
            style={{ display: 'flex', flexDirection: 'column', gap: 22, width: '100%' }}
        >
            {/* ── Folders (tri-state) ─────────────────────────────────────── */}
            <section>
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        gap: 12,
                        marginBottom: 4,
                    }}
                >
                    <div style={groupHead()}>Folders</div>
                    <div
                        data-testid={`connector-${connectorKey}-settings-folder-summary`}
                        style={{ fontSize: 12, color: 'var(--fg-3)' }}
                    >
                        {folderSummary}
                    </div>
                </div>
                <p style={{ margin: '0 0 12px', fontSize: 12.5, color: 'var(--fg-3)', lineHeight: 1.5 }}>
                    Choose per folder whether to sync it.{' '}
                    <b style={{ color: 'var(--fg-2)' }}>Auto</b> follows the source default (sync
                    everything except system folders).
                </p>

                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        marginBottom: 10,
                        flexWrap: 'wrap',
                    }}
                >
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                            background: 'var(--bg-2)',
                            border: '1px solid var(--hairline)',
                            borderRadius: 9,
                            padding: '7px 11px',
                            flex: 1,
                            minWidth: 180,
                        }}
                    >
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--fg-3)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="7" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                        <input
                            data-testid={`connector-${connectorKey}-settings-folder-search`}
                            aria-label="Filter folders"
                            value={folderQuery}
                            onChange={(e) => setFolderQuery(e.target.value)}
                            placeholder="Filter folders…"
                            style={{
                                background: 'transparent',
                                border: 'none',
                                outline: 'none',
                                color: 'var(--fg-0)',
                                font: 'inherit',
                                fontSize: 13,
                                width: '100%',
                            }}
                        />
                    </div>
                    <button
                        type="button"
                        data-testid={`connector-${connectorKey}-settings-folder-reset`}
                        onClick={resetAllAuto}
                        style={miniButton()}
                    >
                        Reset all to Auto
                    </button>
                </div>

                {needsFolders && fetchState === 'loading' && (
                    <div
                        data-testid={`connector-${connectorKey}-settings-folder-loading`}
                        role="status"
                        aria-busy="true"
                        style={boxStyle()}
                    >
                        Loading folders…
                    </div>
                )}
                {needsFolders && fetchState === 'error' && (
                    <div
                        data-testid={`connector-${connectorKey}-settings-folder-fetch-error`}
                        role="alert"
                        style={{ ...boxStyle(), color: '#fca5a5' }}
                    >
                        Could not reach the source to list folders.{' '}
                        <button
                            type="button"
                            data-testid={`connector-${connectorKey}-settings-folder-retry`}
                            onClick={() => foldersQuery.refetch()}
                            style={miniButton()}
                        >
                            Retry
                        </button>
                    </div>
                )}
                {fetchState === 'ready' && shownFolders.length === 0 && (
                    <div
                        data-testid={`connector-${connectorKey}-settings-folder-empty`}
                        role="status"
                        style={boxStyle()}
                    >
                        {allFolders.length === 0 ? 'No folders found.' : 'No folders match your filter.'}
                    </div>
                )}
                {fetchState === 'ready' && shownFolders.length > 0 && (
                    <ul
                        data-testid={`connector-${connectorKey}-settings-folder-list`}
                        style={{
                            listStyle: 'none',
                            margin: 0,
                            padding: 0,
                            border: '1px solid var(--hairline)',
                            borderRadius: 11,
                            background: 'var(--bg-1)',
                            maxHeight: 260,
                            overflowY: 'auto',
                        }}
                    >
                        {shownFolders.map((folder) => (
                            <FolderRow
                                key={folder}
                                connectorKey={connectorKey}
                                folder={folder}
                                rule={ruleOf(folder)}
                                missing={needsFolders && !liveSet.has(folder)}
                                onRule={(r) => setRule(folder, r)}
                            />
                        ))}
                    </ul>
                )}
            </section>

            {/* ── Sync window ─────────────────────────────────────────────── */}
            {windowField && (
                <section>
                    <div style={groupHead()}>Sync window</div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                        <input
                            id={`connector-${connectorKey}-settings-${slug(WINDOW)}`}
                            data-testid={`connector-${connectorKey}-settings-${slug(WINDOW)}`}
                            aria-label={windowField.label}
                            type="number"
                            value={String(values[WINDOW] ?? '')}
                            onChange={(e) => setValue(WINDOW, e.target.value)}
                            style={{ ...inputStyle(), width: 110 }}
                        />
                        <span style={{ fontSize: 13, color: 'var(--fg-2)' }}>days back to import</span>
                    </div>
                    <div style={{ fontSize: 11.5, color: 'var(--fg-3)', marginTop: 6 }}>
                        {windowField.help ?? 'Set to 0 to import all history.'}
                    </div>
                    {errorFor(WINDOW) && (
                        <span
                            data-testid={`connector-${connectorKey}-settings-${slug(WINDOW)}-error`}
                            role="alert"
                            style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}
                        >
                            {errorFor(WINDOW)}
                        </span>
                    )}
                </section>
            )}

            {/* ── Scope (checkbox → toggle switch) ────────────────────────── */}
            {scopeFields.length > 0 && (
                <section>
                    <div style={groupHead()}>Scope</div>
                    <div style={{ display: 'flex', flexDirection: 'column' }}>
                        {scopeFields.map((field) => (
                            <ScopeToggle
                                key={field.name}
                                connectorKey={connectorKey}
                                field={field}
                                checked={Boolean(values[field.name])}
                                onToggle={() => setValue(field.name, !values[field.name])}
                            />
                        ))}
                    </div>
                </section>
            )}

            {/* ── Remaining schema groups (Content / Filtering / Attachments) ── */}
            {remainingGroups.map((g) => (
                <fieldset
                    key={g.group}
                    data-testid={`connector-${connectorKey}-settings-group-${slug(g.group)}`}
                    style={{ border: 0, margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 10 }}
                >
                    <legend style={groupHead()}>{g.group}</legend>
                    {g.fields
                        .filter((f) => isFieldVisible(f, values))
                        .map((field) => (
                            <FieldRow
                                key={field.name}
                                connectorKey={connectorKey}
                                field={field}
                                value={values[field.name]}
                                onChange={(v) => setValue(field.name, v)}
                                liveFolders={live}
                                fetchState={fetchState}
                                onRetryFolders={() => foldersQuery.refetch()}
                                error={errorFor(field.name)}
                            />
                        ))}
                </fieldset>
            ))}

            {submitError && (
                <p
                    data-testid={`connector-${connectorKey}-settings-form-error`}
                    role="alert"
                    style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}
                >
                    {submitError}
                </p>
            )}

            {!footerless && (
                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                    <button
                        type="button"
                        data-testid={`connector-${connectorKey}-settings-form-cancel`}
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid={`connector-${connectorKey}-settings-form-submit`}
                        disabled={isSubmitting || formState === 'loading'}
                        style={buttonStyle('primary', !!isSubmitting || formState === 'loading')}
                    >
                        {isSubmitting ? 'Saving…' : 'Save settings'}
                    </button>
                </div>
            )}
        </form>
    );
}

function FolderRow({
    connectorKey,
    folder,
    rule,
    missing,
    onRule,
}: {
    connectorKey: string;
    folder: string;
    rule: Rule;
    missing: boolean;
    onRule: (r: Rule) => void;
}): ReactNode {
    const base = `connector-${connectorKey}-settings-folder-${slug(folder)}`;
    return (
        <li
            data-testid={base}
            data-rule={rule}
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 10,
                padding: '8px 12px',
                borderBottom: '1px solid var(--hairline)',
            }}
        >
            <svg style={{ flex: 'none' }} width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--fg-3)" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z" />
            </svg>
            <span
                style={{
                    flex: 1,
                    minWidth: 0,
                    fontFamily: 'var(--font-mono)',
                    fontSize: 12.5,
                    color: 'var(--fg-1)',
                    whiteSpace: 'nowrap',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                }}
            >
                {folder}
            </span>
            {missing && (
                <span
                    data-testid={`${base}-missing`}
                    style={{
                        flex: 'none',
                        fontSize: 10.5,
                        color: '#eab308',
                        background: 'rgba(234,179,8,.12)',
                        border: '1px solid rgba(234,179,8,.28)',
                        padding: '1px 7px',
                        borderRadius: 999,
                    }}
                >
                    not on server
                </span>
            )}
            <div
                role="radiogroup"
                aria-label={`Sync rule for ${folder}`}
                style={{
                    flex: 'none',
                    display: 'flex',
                    background: 'var(--bg-2)',
                    border: '1px solid var(--hairline)',
                    borderRadius: 8,
                    padding: 2,
                    gap: 1,
                }}
            >
                {(['auto', 'sync', 'skip'] as Rule[]).map((r) => {
                    const active = rule === r;
                    const meta = RULE_META[r];
                    return (
                        <button
                            key={r}
                            type="button"
                            role="radio"
                            aria-checked={active}
                            data-testid={`${base}-${r}`}
                            onClick={() => onRule(r)}
                            style={{
                                background: active ? meta.bg : 'transparent',
                                color: active ? meta.fg : 'var(--fg-3)',
                                border: 'none',
                                font: 'inherit',
                                fontSize: 11.5,
                                fontWeight: 600,
                                padding: '4px 10px',
                                borderRadius: 6,
                                cursor: 'pointer',
                            }}
                        >
                            {meta.label}
                        </button>
                    );
                })}
            </div>
        </li>
    );
}

function ScopeToggle({
    connectorKey,
    field,
    checked,
    onToggle,
}: {
    connectorKey: string;
    field: CredentialFieldSchema;
    checked: boolean;
    onToggle: () => void;
}): ReactNode {
    const base = `connector-${connectorKey}-settings-${slug(field.name)}`;
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            data-testid={base}
            onClick={onToggle}
            style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 12,
                background: 'transparent',
                border: 'none',
                padding: '11px 2px',
                cursor: 'pointer',
                textAlign: 'left',
                borderBottom: '1px solid var(--hairline)',
                width: '100%',
            }}
        >
            <span style={{ minWidth: 0 }}>
                <span style={{ display: 'block', fontSize: 13.5, fontWeight: 500, color: 'var(--fg-1)' }}>
                    {field.label}
                </span>
                {field.help && (
                    <span style={{ display: 'block', fontSize: 12, color: 'var(--fg-3)', marginTop: 1 }}>
                        {field.help}
                    </span>
                )}
            </span>
            <span
                aria-hidden="true"
                style={{
                    flex: 'none',
                    width: 38,
                    height: 22,
                    borderRadius: 999,
                    background: checked ? 'var(--accent-a, #6366f1)' : 'var(--bg-4, #2a2c31)',
                    position: 'relative',
                    transition: 'background .15s',
                }}
            >
                <span
                    style={{
                        position: 'absolute',
                        top: 2,
                        left: checked ? 18 : 2,
                        width: 18,
                        height: 18,
                        borderRadius: 999,
                        background: '#fff',
                        transition: 'left .15s',
                    }}
                />
            </span>
        </button>
    );
}

function groupHead(): React.CSSProperties {
    return {
        fontSize: 11.5,
        fontWeight: 700,
        textTransform: 'uppercase',
        letterSpacing: '.06em',
        color: 'var(--fg-3)',
        marginBottom: 12,
        padding: 0,
    };
}

function miniButton(): React.CSSProperties {
    return {
        flex: 'none',
        background: 'var(--bg-2)',
        border: '1px solid var(--hairline)',
        color: 'var(--fg-2)',
        font: 'inherit',
        fontSize: 12,
        fontWeight: 600,
        padding: '7px 11px',
        borderRadius: 8,
        cursor: 'pointer',
    };
}
