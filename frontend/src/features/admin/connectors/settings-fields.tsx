import { useMemo, useState, type KeyboardEvent, type ReactNode } from 'react';
import type { CredentialFieldSchema } from './connectors.api';

/*
 * Shared schema-driven field primitives for the connection-settings surface,
 * extracted from ConnectionSettingsForm so BOTH the generic settings form AND
 * the redesigned tri-state Sync-settings tab (SyncSettingsForm) render the SAME
 * field widgets for the parts each doesn't specialise (a checkbox is a checkbox,
 * a tags list is a tags list) — no duplicated, subtly-divergent copies.
 *
 * Every field is rendered by its `type` (R23 — no connector-specific markup):
 * `multiselect` (a live folder picker when `discovery === 'folders'`, else a
 * fixed option list), `tags` (an open chip list), `number`, `select`, `checkbox`,
 * `text`. Testids stay `connector-{key}-settings-{slug(name)}*` so existing
 * Playwright/Vitest selectors are unaffected.
 */

export function slug(s: string): string {
    return s.replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase() || 'field';
}

/** Read a dotted path from a nested object. */
export function getPath(obj: unknown, path: string): unknown {
    return path.split('.').reduce<unknown>((acc, key) => {
        if (acc && typeof acc === 'object' && key in (acc as Record<string, unknown>)) {
            return (acc as Record<string, unknown>)[key];
        }
        return undefined;
    }, obj);
}

/** Write a dotted path into a nested object (mutating). */
export function setPath(obj: Record<string, unknown>, path: string, value: unknown): void {
    const keys = path.split('.');
    let cur = obj;
    for (let i = 0; i < keys.length - 1; i++) {
        const k = keys[i];
        if (typeof cur[k] !== 'object' || cur[k] === null) cur[k] = {};
        cur = cur[k] as Record<string, unknown>;
    }
    cur[keys[keys.length - 1]] = value;
}

export function asStringList(v: unknown): string[] {
    return Array.isArray(v) ? v.map((x) => String(x)) : [];
}

export function seedValue(field: CredentialFieldSchema, settings: Record<string, unknown>): unknown {
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
        case 'select':
            // Preserve null (don't stringify to '') so a nullable select seeds the
            // empty option and round-trips a clear-to-default rather than submitting
            // '' (which matches no option and would 422).
            return raw == null ? null : String(raw);
        default:
            return raw == null ? '' : String(raw);
    }
}

export interface GroupedSchema {
    group: string;
    fields: CredentialFieldSchema[];
}

export function groupFields(schema: CredentialFieldSchema[]): GroupedSchema[] {
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

/**
 * A field with a `showIf` only applies when its controlling field holds the
 * expected value. Compared by string form: `number` values are stored as strings,
 * so a strict === against a numeric/boolean `showIf.equals` would never match.
 */
export function isFieldVisible(
    field: CredentialFieldSchema,
    values: Record<string, unknown>,
): boolean {
    if (field.showIf === null) return true;
    return String(values[field.showIf.field]) === String(field.showIf.equals);
}

export interface FieldRowProps {
    connectorKey: string;
    field: CredentialFieldSchema;
    value: unknown;
    onChange: (v: unknown) => void;
    liveFolders: string[];
    fetchState: 'loading' | 'error' | 'ready';
    onRetryFolders: () => void;
    error?: string;
}

export function FieldRow({
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
        const nullable = !field.required;
        return (
            <label htmlFor={base} style={fieldCol()}>
                {label}
                {help}
                <select
                    id={base}
                    data-testid={base}
                    value={String(value ?? '')}
                    onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)}
                    style={inputStyle()}
                >
                    {nullable && <option value="">— connector default —</option>}
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

export function FolderOrOptionMultiselect({
    base,
    field,
    selected,
    onChange,
    liveFolders,
    fetchState,
    onRetryFolders,
}: MultiselectProps): ReactNode {
    const live = field.discovery === 'folders';
    const options = useMemo(() => {
        if (!live) return Object.keys(field.options);
        return Array.from(new Set([...liveFolders, ...selected])).sort((a, b) => a.localeCompare(b));
    }, [live, field.options, liveFolders, selected]);
    const liveSet = useMemo(() => new Set(liveFolders), [liveFolders]);
    const selectedSet = useMemo(() => new Set(selected), [selected]);
    const testids = useMemo(() => {
        const seen = new Set<string>();
        return options.map((opt, i) => {
            const s = slug(opt);
            const id = `${base}-opt-${s}`;
            if (seen.has(s)) return `${id}-${i}`;
            seen.add(s);
            return id;
        });
    }, [options, base]);

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
                const optId = `${base}-opt-${i}`;
                const testid = testids[i];
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

export function TagInput({ base, ariaLabelledBy, values, onChange }: TagInputProps): ReactNode {
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
    const chipIds = useMemo(() => {
        const seen = new Set<string>();
        return values.map((v, i) => {
            const s = slug(v);
            if (seen.has(s)) return `${s}-${i}`;
            seen.add(s);
            return s;
        });
    }, [values]);

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
                    {values.map((v, i) => (
                        <li key={`${v}-${i}`} style={chipStyle()}>
                            <span style={{ fontFamily: 'var(--font-mono)' }}>{v}</span>
                            <button
                                type="button"
                                data-testid={`${base}-chip-${chipIds[i]}-remove`}
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

/**
 * Assemble the nested `settings` PATCH payload from the current field values —
 * shared so the generic form and the tri-state form serialise identically:
 * hidden (showIf-off) fields are skipped; an empty number → null (clear to the
 * connector default); a non-finite number → the raw string so the BE 422s rather
 * than silently dropping invalid input.
 */
export function buildSettingsPayload(
    schema: CredentialFieldSchema[],
    values: Record<string, unknown>,
): Record<string, unknown> {
    const settings: Record<string, unknown> = {};
    for (const f of schema) {
        if (!isFieldVisible(f, values)) continue;
        const v = values[f.name];
        if (f.type === 'number') {
            const t = String(v ?? '').trim();
            if (t === '') {
                setPath(settings, f.name, null);
            } else {
                const n = Number(t);
                setPath(settings, f.name, Number.isFinite(n) ? n : t);
            }
            continue;
        }
        setPath(settings, f.name, v);
    }
    return settings;
}

export function fieldCol(): React.CSSProperties {
    return { display: 'flex', flexDirection: 'column', gap: 4 };
}

export function boxStyle(): React.CSSProperties {
    return {
        padding: 12,
        textAlign: 'center',
        color: 'var(--fg-3)',
        fontSize: 12,
        border: '1px dashed var(--hairline)',
        borderRadius: 8,
    };
}

export function listStyle(): React.CSSProperties {
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

export function inputStyle(): React.CSSProperties {
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

export function buttonStyle(variant: 'primary' | 'secondary', disabled: boolean): React.CSSProperties {
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
