import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import type { AdminProject } from '../projects/admin-projects.api';
import type {
    ConfigureConnectorPayload,
    ConnectorEntry,
    CredentialFieldSchema,
} from './connectors.api';

/**
 * v8.17 — modal form for configuring a CREDENTIAL-BASED connector (IMAP first).
 *
 * Entirely SCHEMA-DRIVEN: every field, type, option, default, conditional
 * visibility (`showIf`) and which value is a secret comes from
 * `entry.credential_form_schema` (the BE's `SupportsCredentialForm` contract).
 * There is no IMAP-specific markup here — any future credential connector renders
 * for free.
 *
 * R11: every input + the submit/cancel carry a stable
 *      `connector-{key}-form-{name}` testid; per-field 422 errors render under
 *      the field with `connector-{key}-form-{name}-error`.
 * R15: every input has a bound `<label htmlFor>`; secrets use `type=password`
 *      and are never pre-filled; the dialog is `role="dialog"` + `aria-modal`;
 *      Esc closes.
 */

export interface CredentialConnectorFormProps {
    /** The connector being configured — must carry a non-null credential_form_schema. */
    entry: ConnectorEntry;
    /** v8.20 — real project registry for the binding dropdown (R18). */
    projects: AdminProject[];
    onSubmit: (payload: ConfigureConnectorPayload) => void;
    onClose: () => void;
    /** Top-level error (e.g. the BE's "IMAP login failed" 422 message). */
    submitError?: string | null;
    /** Per-field validation errors keyed by field name (from a 422 response). */
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
}

type FieldValue = string | number | boolean;

function initialValue(field: CredentialFieldSchema): FieldValue {
    // Secrets are NEVER pre-filled — even if a schema mistakenly ships a default
    // for a password, the input must render empty (the BE never returns a saved
    // secret either).
    if (field.secret || field.type === 'password') {
        return '';
    }
    if (field.type === 'checkbox') {
        return field.default === true;
    }
    if (field.default !== null && field.default !== undefined) {
        return field.default as FieldValue;
    }
    return '';
}

export function CredentialConnectorForm({
    entry,
    projects,
    onSubmit,
    onClose,
    submitError,
    fieldErrors,
    isSubmitting,
}: CredentialConnectorFormProps): ReactNode {
    const schema = useMemo(() => entry.credential_form_schema ?? [], [entry.credential_form_schema]);

    // v8.20 — account label (required) + optional project binding. These are NOT
    // schema fields; the host injects them into the configure payload.
    const [label, setLabel] = useState('');
    const [projectKey, setProjectKey] = useState('');

    const [values, setValues] = useState<Record<string, FieldValue>>(() => {
        const seed: Record<string, FieldValue> = {};
        for (const field of schema) {
            seed[field.name] = initialValue(field);
        }
        return seed;
    });

    // Re-seed if the dialog instance is reused for a different connector.
    useEffect(() => {
        const seed: Record<string, FieldValue> = {};
        for (const field of schema) {
            seed[field.name] = initialValue(field);
        }
        setValues(seed);
    }, [schema]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const isVisible = (field: CredentialFieldSchema): boolean =>
        field.showIf === null || values[field.showIf.field] === field.showIf.equals;

    const setValue = (name: string, value: FieldValue) =>
        setValues((prev) => ({ ...prev, [name]: value }));

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        // Only submit the VISIBLE fields — the BE also honours showIf, but
        // sending hidden defaults would pollute the payload. An emptied optional
        // field is OMITTED (not sent as '' / null) so the BE applies the schema
        // default (e.g. port 993) instead of overriding it. Required fields can't
        // reach here empty — the browser's `required` blocks the submit first.
        const payload: ConfigureConnectorPayload = {};
        for (const field of schema) {
            if (!isVisible(field)) continue;
            const value = values[field.name];
            if (value === '') continue;
            payload[field.name] = value;
        }
        // v8.20 — inject the account label (required) + optional project binding.
        // An empty project is OMITTED so the BE applies the tenant default.
        payload.label = label.trim();
        if (projectKey !== '') {
            payload.project_key = projectKey;
        }
        onSubmit(payload);
    };

    // Group fields by their `group` heading, preserving declaration order.
    const groups = useMemo(() => {
        const ordered: Array<{ group: string | null; fields: CredentialFieldSchema[] }> = [];
        for (const field of schema) {
            const last = ordered[ordered.length - 1];
            if (last && last.group === (field.group ?? null)) {
                last.fields.push(field);
            } else {
                ordered.push({ group: field.group ?? null, fields: [field] });
            }
        }
        return ordered;
    }, [schema]);

    const titleId = `connector-${entry.key}-form-title`;

    return (
        <div
            data-testid={`connector-${entry.key}-form-backdrop`}
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0,0,0,.4)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 100,
            }}
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-busy={isSubmitting}
                data-testid={`connector-${entry.key}-form`}
                data-state={isSubmitting ? 'loading' : 'idle'}
                onSubmit={handleSubmit}
                style={{
                    background: 'var(--panel-solid, #1a1a22)',
                    border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
                    borderRadius: 12,
                    boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.4))',
                    minWidth: 380,
                    maxWidth: 460,
                    maxHeight: '85vh',
                    overflowY: 'auto',
                    padding: 16,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                }}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    Connect {entry.display_name}
                </h2>

                {/* v8.20 — account label + project binding (injected, not schema). */}
                <label
                    htmlFor={`connector-${entry.key}-form-label`}
                    style={{ display: 'flex', flexDirection: 'column', gap: 4 }}
                >
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>
                        Account label<span style={{ color: 'var(--err, #fca5a5)' }}> *</span>
                    </span>
                    <input
                        id={`connector-${entry.key}-form-label`}
                        data-testid={`connector-${entry.key}-form-label`}
                        type="text"
                        required
                        // At least one non-whitespace char — matches the trimmed
                        // submission so a whitespace-only label can't 422. JS-string
                        // expression so the DOM receives a literal `\S`.
                        pattern={'.*\\S.*'}
                        value={label}
                        onChange={(e) => setLabel(e.target.value)}
                        placeholder="e.g. Support, Sales"
                        style={inputStyle()}
                    />
                    {fieldErrors?.label && (
                        <span
                            data-testid={`connector-${entry.key}-form-label-error`}
                            role="alert"
                            style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}
                        >
                            {fieldErrors.label}
                        </span>
                    )}
                </label>

                <label
                    htmlFor={`connector-${entry.key}-form-project_key`}
                    style={{ display: 'flex', flexDirection: 'column', gap: 4 }}
                >
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>KB project binding</span>
                    <select
                        id={`connector-${entry.key}-form-project_key`}
                        data-testid={`connector-${entry.key}-form-project_key`}
                        value={projectKey}
                        onChange={(e) => setProjectKey(e.target.value)}
                        style={inputStyle()}
                    >
                        <option value="">Global (tenant default)</option>
                        {projects.map((p) => (
                            <option key={p.project_key} value={p.project_key}>
                                {p.name} ({p.project_key})
                            </option>
                        ))}
                    </select>
                    {fieldErrors?.project_key && (
                        <span
                            data-testid={`connector-${entry.key}-form-project_key-error`}
                            role="alert"
                            style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}
                        >
                            {fieldErrors.project_key}
                        </span>
                    )}
                </label>

                {groups.map((grp, gi) => (
                    <div
                        key={grp.group ?? `g${gi}`}
                        style={{ display: 'flex', flexDirection: 'column', gap: 10 }}
                    >
                        {grp.group && (
                            <div
                                style={{
                                    fontSize: 10.5,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.04em',
                                    color: 'var(--fg-3)',
                                    marginTop: gi === 0 ? 0 : 4,
                                }}
                            >
                                {grp.group}
                            </div>
                        )}
                        {grp.fields.filter(isVisible).map((field) => (
                            <Field
                                key={field.name}
                                connectorKey={entry.key}
                                field={field}
                                value={values[field.name]}
                                error={fieldErrors?.[field.name]}
                                onChange={(v) => setValue(field.name, v)}
                            />
                        ))}
                    </div>
                ))}

                {submitError && (
                    <p
                        data-testid={`connector-${entry.key}-form-error`}
                        role="alert"
                        style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}
                    >
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid={`connector-${entry.key}-form-cancel`}
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid={`connector-${entry.key}-form-submit`}
                        disabled={isSubmitting}
                        style={buttonStyle('primary', !!isSubmitting)}
                    >
                        {isSubmitting ? 'Connecting…' : 'Connect'}
                    </button>
                </div>
            </form>
        </div>
    );
}

interface FieldProps {
    connectorKey: string;
    field: CredentialFieldSchema;
    value: FieldValue;
    error?: string;
    onChange: (value: FieldValue) => void;
}

function Field({ connectorKey, field, value, error, onChange }: FieldProps): ReactNode {
    const id = `connector-${connectorKey}-form-${field.name}`;
    const testid = id;

    return (
        <label htmlFor={id} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>
                {field.label}
                {field.required && <span style={{ color: 'var(--err, #fca5a5)' }}> *</span>}
            </span>

            {field.type === 'select' ? (
                <select
                    id={id}
                    data-testid={testid}
                    required={field.required}
                    value={String(value ?? '')}
                    onChange={(e) => onChange(e.target.value)}
                    style={inputStyle()}
                >
                    {Object.entries(field.options).map(([val, label]) => (
                        <option key={val} value={val}>
                            {label}
                        </option>
                    ))}
                </select>
            ) : field.type === 'checkbox' ? (
                <input
                    id={id}
                    data-testid={testid}
                    type="checkbox"
                    checked={value === true}
                    onChange={(e) => onChange(e.target.checked)}
                    style={{ width: 16, height: 16, accentColor: 'var(--accent, #6366f1)' }}
                />
            ) : (
                <input
                    id={id}
                    data-testid={testid}
                    type={field.type === 'password' ? 'password' : field.type === 'number' ? 'number' : 'text'}
                    required={field.required}
                    // Secrets are never pre-filled; the BE never returns a saved value.
                    autoComplete={field.secret ? 'new-password' : 'off'}
                    value={value === null || value === undefined ? '' : String(value)}
                    onChange={(e) => {
                        if (field.type !== 'number') {
                            onChange(e.target.value);
                            return;
                        }
                        // A cleared number input yields NaN — store '' instead so
                        // the controlled value stays in sync and the field is
                        // omitted from the payload (the BE default applies).
                        const n = e.target.valueAsNumber;
                        onChange(Number.isNaN(n) ? '' : n);
                    }}
                    placeholder={field.help ?? undefined}
                    style={inputStyle()}
                />
            )}

            {field.help && field.type !== 'password' && (
                <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>{field.help}</span>
            )}
            {error && (
                <span
                    data-testid={`${id}-error`}
                    role="alert"
                    style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}
                >
                    {error}
                </span>
            )}
        </label>
    );
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
