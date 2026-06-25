import {
    useEffect,
    useMemo,
    useState,
    type FormEvent,
    type KeyboardEvent,
    type ReactNode,
} from 'react';
import type { ConnectorInstallationDto, CredentialFieldSchema } from './connectors.api';
import { useInstallationFolders } from './connectors-hooks';

/**
 * v8.25 — the SCHEMA-DRIVEN connection-settings editor for a connector account.
 *
 * Renders the connector's full `connection_settings_schema` (grouped by `group`)
 * seeded from the account's current `settings` (a nested partial of config_json),
 * and PATCHes a nested `settings` object keyed by each field's dotted name. There
 * is NO connector-specific markup (R23): every field is rendered by its `type` —
 * `multiselect` (a live folder picker when `discovery === 'folders'`, else a fixed
 * option list), `tags` (an open chip list), `number`, `select`, `checkbox`, `text`.
 *
 * Supersedes the v8.24 folder-only picker: the same modal now exposes the WHOLE
 * editable surface (folder include/exclude, sync window, sender/recipient/subject
 * filters, body format, scope flags, attachments).
 *
 * R11/R29 testids `connector-{key}-settings-form*` + per-field
 * `connector-{key}-settings-{slug(name)}*`; R15 every control has a bound label,
 * the dialog is role=dialog + aria-modal, Esc closes; R14 the folder fetch
 * loading/error/empty/ready states are observable via `data-state`.
 */

export interface ConnectionSettingsFormProps {
    connectorKey: string;
    account: ConnectorInstallationDto;
    /** The nested `settings` payload (a partial of config_json). */
    onSubmit: (settings: Record<string, unknown>) => void;
    onClose: () => void;
    submitError?: string | null;
    /** BE 422 field errors keyed by the dotted setting path (e.g. `settings.date_window_days`). */
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
}

function slug(s: string): string {
    return s.replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase() || 'field';
}

/** Read a dotted path from a nested object. */
function getPath(obj: unknown, path: string): unknown {
    return path.split('.').reduce<unknown>((acc, key) => {
        if (acc && typeof acc === 'object' && key in (acc as Record<string, unknown>)) {
            return (acc as Record<string, unknown>)[key];
        }
        return undefined;
    }, obj);
}

/** Write a dotted path into a nested object (mutating). */
function setPath(obj: Record<string, unknown>, path: string, value: unknown): void {
    const keys = path.split('.');
    let cur = obj;
    for (let i = 0; i < keys.length - 1; i++) {
        const k = keys[i];
        if (typeof cur[k] !== 'object' || cur[k] === null) cur[k] = {};
        cur = cur[k] as Record<string, unknown>;
    }
    cur[keys[keys.length - 1]] = value;
}

function asStringList(v: unknown): string[] {
    return Array.isArray(v) ? v.map((x) => String(x)) : [];
}

function seedValue(field: CredentialFieldSchema, settings: Record<string, unknown>): unknown {
    const stored = getPath(settings, field.name);
    const raw = stored !== undefined ? stored : field.default;
    switch (field.type) {
        case 'multiselect':
        case 'tags':
            return asStringList(raw);
        case 'checkbox':
            return Boolean(raw);
        case 'number':
            return raw == null ? '' : String(raw);
        default:
            return raw == null ? '' : String(raw);
    }
}

interface GroupedSchema {
    group: string;
    fields: CredentialFieldSchema[];
}

function groupFields(schema: CredentialFieldSchema[]): GroupedSchema[] {
    const order: string[] = [];
    const byGroup = new Map<string, CredentialFieldSchema[]>();
    for (const f of schema) {
        const g = f.group ?? 'Settings';
        if (!byGroup.has(g)) {
            byGroup.set(g, []);
            order.push(g);
        }
        byGroup.get(g)!.push(f);
    }
    return order.map((group) => ({ group, fields: byGroup.get(group)! }));
}

export function ConnectionSettingsForm({
    connectorKey,
    account,
    onSubmit,
    onClose,
    submitError,
    fieldErrors,
    isSubmitting,
}: ConnectionSettingsFormProps): ReactNode {
    const schema = account.connection_settings_schema ?? [];
    const needsFolders = schema.some((f) => f.discovery === 'folders');
    const foldersQuery = useInstallationFolders(account.id, needsFolders);

    const [values, setValues] = useState<Record<string, unknown>>(() => {
        const seed: Record<string, unknown> = {};
        for (const f of schema) seed[f.name] = seedValue(f, account.settings ?? {});
        return seed;
    });

    useEffect(() => {
        const onKey = (e: globalThis.KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const groups = useMemo(() => groupFields(schema), [schema]);

    const live = foldersQuery.data ?? [];
    const fetchState: 'loading' | 'error' | 'ready' = foldersQuery.isLoading
        ? 'loading'
        : foldersQuery.isError
          ? 'error'
          : 'ready';

    const setValue = (name: string, v: unknown) => setValues((cur) => ({ ...cur, [name]: v }));

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const settings: Record<string, unknown> = {};
        for (const f of schema) {
            const v = values[f.name];
            if (f.type === 'number') {
                const t = String(v ?? '').trim();
                if (t === '') {
                    // Empty → send null to CLEAR the override back to the connector
                    // default (the BE unsets it); a number sets it.
                    setPath(settings, f.name, null);
                } else {
                    const n = Number(t);
                    if (Number.isFinite(n)) setPath(settings, f.name, n);
                }
                continue;
            }
            setPath(settings, f.name, v);
        }
        onSubmit(settings);
    };

    const titleId = `connector-${connectorKey}-settings-form-title`;
    const formState = needsFolders ? fetchState : 'ready';

    return (
        <div
            data-testid={`connector-${connectorKey}-settings-form-backdrop`}
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={backdropStyle()}
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-busy={isSubmitting}
                data-testid={`connector-${connectorKey}-settings-form`}
                data-state={formState}
                onSubmit={handleSubmit}
                style={dialogStyle()}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    Connection settings — {account.label}
                </h2>

                <div style={{ overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 14, paddingRight: 4 }}>
                    {groups.map((g) => (
                        <fieldset
                            key={g.group}
                            data-testid={`connector-${connectorKey}-settings-group-${slug(g.group)}`}
                            style={{ border: 0, margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 8 }}
                        >
                            <legend style={{ color: 'var(--fg-2)', fontSize: 11, padding: 0, fontWeight: 600 }}>
                                {g.group}
                            </legend>
                            {g.fields.map((field) => (
                                <FieldRow
                                    key={field.name}
                                    connectorKey={connectorKey}
                                    field={field}
                                    value={values[field.name]}
                                    onChange={(v) => setValue(field.name, v)}
                                    liveFolders={live}
                                    fetchState={fetchState}
                                    onRetryFolders={() => foldersQuery.refetch()}
                                    error={fieldErrors?.[`settings.${field.name}`]}
                                />
                            ))}
                        </fieldset>
                    ))}
                </div>

                {submitError && (
                    <p
                        data-testid={`connector-${connectorKey}-settings-form-error`}
                        role="alert"
                        style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}
                    >
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
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
            </form>
        </div>
    );
}

interface FieldRowProps {
    connectorKey: string;
    field: CredentialFieldSchema;
    value: unknown;
    onChange: (v: unknown) => void;
    liveFolders: string[];
    fetchState: 'loading' | 'error' | 'ready';
    onRetryFolders: () => void;
    error?: string;
}

function FieldRow({
    connectorKey,
    field,
    value,
    onChange,
    liveFolders,
    fetchState,
    onRetryFolders,
    error,
}: FieldRowProps): ReactNode {
    const base = `connector-${connectorKey}-settings-${slug(field.name)}`;
    const labelId = `${base}-label`;

    const label = (
        <span id={labelId} style={{ color: 'var(--fg-1)', fontSize: 11.5 }}>
            {field.label}
        </span>
    );
    const help = field.help ? (
        <span style={{ color: 'var(--fg-3)', fontSize: 10 }}>{field.help}</span>
    ) : null;
    const errEl = error ? (
        <span data-testid={`${base}-error`} role="alert" style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}>
            {error}
        </span>
    ) : null;

    if (field.type === 'checkbox') {
        return (
            <label htmlFor={base} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
                <input
                    id={base}
                    data-testid={base}
                    type="checkbox"
                    checked={Boolean(value)}
                    onChange={(e) => onChange(e.target.checked)}
                />
                {label}
                {help}
                {errEl}
            </label>
        );
    }

    if (field.type === 'multiselect') {
        return (
            <div style={fieldCol()}>
                {label}
                {help}
                <FolderOrOptionMultiselect
                    base={base}
                    field={field}
                    selected={asStringList(value)}
                    onChange={onChange}
                    liveFolders={liveFolders}
                    fetchState={fetchState}
                    onRetryFolders={onRetryFolders}
                />
                {errEl}
            </div>
        );
    }

    if (field.type === 'tags') {
        return (
            <div style={fieldCol()}>
                {label}
                {help}
                <TagInput base={base} ariaLabelledBy={labelId} values={asStringList(value)} onChange={onChange} />
                {errEl}
            </div>
        );
    }

    if (field.type === 'select') {
        return (
            <label htmlFor={base} style={fieldCol()}>
                {label}
                {help}
                <select
                    id={base}
                    data-testid={base}
                    value={String(value ?? '')}
                    onChange={(e) => onChange(e.target.value)}
                    style={inputStyle()}
                >
                    {Object.entries(field.options).map(([val, lbl]) => (
                        <option key={val} value={val}>
                            {lbl}
                        </option>
                    ))}
                </select>
                {errEl}
            </label>
        );
    }

    // number | text
    return (
        <label htmlFor={base} style={fieldCol()}>
            {label}
            {help}
            <input
                id={base}
                data-testid={base}
                type={field.type === 'number' ? 'number' : 'text'}
                value={String(value ?? '')}
                onChange={(e) => onChange(e.target.value)}
                style={inputStyle()}
            />
            {errEl}
        </label>
    );
}

interface MultiselectProps {
    base: string;
    field: CredentialFieldSchema;
    selected: string[];
    onChange: (v: string[]) => void;
    liveFolders: string[];
    fetchState: 'loading' | 'error' | 'ready';
    onRetryFolders: () => void;
}

function FolderOrOptionMultiselect({
    base,
    field,
    selected,
    onChange,
    liveFolders,
    fetchState,
    onRetryFolders,
}: MultiselectProps): ReactNode {
    const live = field.discovery === 'folders';
    // Live folders: union of server folders + already-selected (so a saved-but-
    // vanished folder stays visible, checked + flagged). Fixed: the schema options.
    const options = useMemo(() => {
        if (!live) return Object.keys(field.options);
        return Array.from(new Set([...liveFolders, ...selected])).sort((a, b) => a.localeCompare(b));
    }, [live, field.options, liveFolders, selected]);
    const liveSet = useMemo(() => new Set(liveFolders), [liveFolders]);
    const selectedSet = useMemo(() => new Set(selected), [selected]);

    const toggle = (path: string) => {
        const next = new Set(selectedSet);
        if (next.has(path)) next.delete(path);
        else next.add(path);
        onChange(options.filter((o) => next.has(o)).concat([...next].filter((o) => !options.includes(o))));
    };

    if (live && fetchState === 'loading') {
        return (
            <div data-testid={`${base}-loading`} role="status" aria-busy="true" style={boxStyle()}>
                Loading folders…
            </div>
        );
    }
    if (live && fetchState === 'error') {
        return (
            <div data-testid={`${base}-fetch-error`} role="alert" style={{ ...boxStyle(), color: '#fca5a5' }}>
                Could not reach the source to list folders.{' '}
                <button type="button" data-testid={`${base}-retry`} onClick={onRetryFolders} style={ghostButton()}>
                    Retry
                </button>
            </div>
        );
    }
    if (options.length === 0) {
        return (
            <div data-testid={`${base}-empty`} role="status" style={boxStyle()}>
                {live ? 'No folders found.' : 'No options.'}
            </div>
        );
    }

    return (
        <ul data-testid={`${base}-list`} role="group" aria-labelledby={`${base}-label`} style={listStyle()}>
            {options.map((opt, i) => {
                // id is index-based so it is GUARANTEED unique — two distinct folder
                // paths can slug-collide (e.g. "Foo Bar" vs "Foo-Bar"), and a
                // duplicate id silently breaks label↔input association. The
                // data-testid stays slug-based for readable, stable selectors.
                const optId = `${base}-opt-${i}`;
                const testid = `${base}-opt-${slug(opt)}`;
                const missing = live && !liveSet.has(opt);
                const display = live ? opt : (field.options[opt] ?? opt);
                return (
                    <li key={opt} style={{ display: 'flex' }}>
                        <label htmlFor={optId} style={optionLabelStyle()}>
                            <input
                                id={optId}
                                data-testid={testid}
                                type="checkbox"
                                checked={selectedSet.has(opt)}
                                onChange={() => toggle(opt)}
                            />
                            <span style={{ fontFamily: live ? 'var(--font-mono)' : undefined }}>{display}</span>
                            {missing && (
                                <span data-testid={`${testid}-missing`} style={{ fontSize: 10, color: 'var(--fg-3)' }}>
                                    (not found on server)
                                </span>
                            )}
                        </label>
                    </li>
                );
            })}
        </ul>
    );
}

interface TagInputProps {
    base: string;
    ariaLabelledBy: string;
    values: string[];
    onChange: (v: string[]) => void;
}

function TagInput({ base, ariaLabelledBy, values, onChange }: TagInputProps): ReactNode {
    const [draft, setDraft] = useState('');

    const add = () => {
        const v = draft.trim();
        if (v === '' || values.includes(v)) {
            setDraft('');
            return;
        }
        onChange([...values, v]);
        setDraft('');
    };
    const remove = (v: string) => onChange(values.filter((x) => x !== v));

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            add();
        } else if (e.key === 'Backspace' && draft === '' && values.length > 0) {
            remove(values[values.length - 1]);
        }
    };

    return (
        <div data-testid={`${base}`} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            {values.length > 0 && (
                <ul data-testid={`${base}-chips`} style={{ ...listStyle(), maxHeight: 'none', flexDirection: 'row', flexWrap: 'wrap', padding: 4, gap: 4 }}>
                    {values.map((v) => (
                        <li key={v} style={chipStyle()}>
                            <span style={{ fontFamily: 'var(--font-mono)' }}>{v}</span>
                            <button
                                type="button"
                                data-testid={`${base}-chip-${slug(v)}-remove`}
                                aria-label={`Remove ${v}`}
                                onClick={() => remove(v)}
                                style={chipRemoveStyle()}
                            >
                                ×
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <div style={{ display: 'flex', gap: 6 }}>
                <input
                    data-testid={`${base}-input`}
                    aria-labelledby={ariaLabelledBy}
                    type="text"
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={onKeyDown}
                    onBlur={add}
                    placeholder="Type and press Enter"
                    style={{ ...inputStyle(), flex: 1 }}
                />
                <button type="button" data-testid={`${base}-add`} onClick={add} style={buttonStyle('secondary', false)}>
                    Add
                </button>
            </div>
        </div>
    );
}

function backdropStyle(): React.CSSProperties {
    return {
        position: 'fixed',
        inset: 0,
        background: 'rgba(0,0,0,.4)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 100,
    };
}

function dialogStyle(): React.CSSProperties {
    return {
        background: 'var(--panel-solid, #1a1a22)',
        border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
        borderRadius: 12,
        boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.4))',
        minWidth: 420,
        maxWidth: 560,
        maxHeight: '88vh',
        padding: 16,
        display: 'flex',
        flexDirection: 'column',
        gap: 12,
        overflow: 'hidden',
    };
}

function fieldCol(): React.CSSProperties {
    return { display: 'flex', flexDirection: 'column', gap: 4 };
}

function boxStyle(): React.CSSProperties {
    return {
        padding: 12,
        textAlign: 'center',
        color: 'var(--fg-3)',
        fontSize: 12,
        border: '1px dashed var(--hairline)',
        borderRadius: 8,
    };
}

function listStyle(): React.CSSProperties {
    return {
        listStyle: 'none',
        margin: 0,
        padding: 0,
        display: 'flex',
        flexDirection: 'column',
        gap: 2,
        overflowY: 'auto',
        maxHeight: '28vh',
        border: '1px solid var(--hairline)',
        borderRadius: 8,
    };
}

function optionLabelStyle(): React.CSSProperties {
    return {
        display: 'flex',
        alignItems: 'center',
        gap: 8,
        padding: '5px 10px',
        fontSize: 12,
        color: 'var(--fg-1)',
        width: '100%',
        cursor: 'pointer',
    };
}

function chipStyle(): React.CSSProperties {
    return {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 4,
        padding: '2px 4px 2px 8px',
        fontSize: 11,
        color: 'var(--fg-1)',
        background: 'var(--bg-3, rgba(255,255,255,.06))',
        border: '1px solid var(--hairline)',
        borderRadius: 6,
    };
}

function chipRemoveStyle(): React.CSSProperties {
    return {
        background: 'transparent',
        border: 0,
        color: 'inherit',
        cursor: 'pointer',
        fontSize: 13,
        lineHeight: 1,
        padding: '0 2px',
    };
}

function inputStyle(): React.CSSProperties {
    return {
        padding: '5px 8px',
        borderRadius: 6,
        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
        background: 'var(--bg-3, rgba(255,255,255,.04))',
        color: 'var(--fg-0)',
        fontSize: 12,
    };
}

function ghostButton(): React.CSSProperties {
    return {
        marginLeft: 8,
        padding: '3px 10px',
        fontSize: 11,
        background: 'transparent',
        color: 'inherit',
        border: '1px solid currentColor',
        borderRadius: 6,
        cursor: 'pointer',
    };
}

function buttonStyle(variant: 'primary' | 'secondary', disabled: boolean): React.CSSProperties {
    const isPrimary = variant === 'primary';
    return {
        padding: '5px 14px',
        borderRadius: 6,
        border: '1px solid ' + (isPrimary ? 'var(--accent, #6366f1)' : 'var(--panel-border, rgba(255,255,255,.15))'),
        background: isPrimary ? 'var(--accent, #6366f1)' : 'transparent',
        color: isPrimary ? 'white' : 'var(--fg-1)',
        fontSize: 11.5,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.6 : 1,
    };
}
