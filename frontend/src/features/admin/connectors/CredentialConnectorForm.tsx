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
 * v8.29 — three additive modes on the same schema-driven body:
 *   - `mode='create'` (default): the original Add-account flow (label + project +
 *     Connect → configure). Optionally PREFILLED (`initialValues` / `initialLabel`
 *     / `initialProjectKey`) so an IMPORTED config seeds the form; the secret is
 *     still typed by the operator (never prefilled).
 *   - `mode='edit'`: the Edit → Connection tab. Prefills the connection params,
 *     HIDES the label/project fields (those are the Details tab), and treats the
 *     secret as OPTIONAL — a blank password keeps the current one (the BE
 *     reconfigure verifies against the vaulted credential). Submit → reconfigure.
 *   - `embedded`: render as a plain panel (no backdrop, no title) so a parent
 *     modal (the tabbed AccountEditModal) can host it as tab content.
 *
 * R11: every input + the submit/cancel carry a stable `connector-{key}-form-{name}`
 *      testid; per-field 422 errors render under the field with
 *      `connector-{key}-form-{name}-error`.
 * R15: every input has a bound `<label htmlFor>`; secrets use `type=password` and
 *      are never pre-filled; the dialog is `role="dialog"` + `aria-modal`; Esc closes.
 */

export interface CredentialConnectorFormProps {
    /** The connector being configured — must carry a non-null credential_form_schema. */
    entry: ConnectorEntry;
    /** v8.20 — real project registry for the binding dropdown (R18). */
    projects: AdminProject[];
    onSubmit: (payload: ConfigureConnectorPayload) => void;
    onClose: () => void;
     /**
      * v8.26 — pre-save connection test. When provided AND the effective auth mode
      * is basic (a synchronous ping exists), a "Test connection" button appears and
      * Connect stays DISABLED until the test passes ("Test OK → you can save").
      * Changing a connection field invalidates a prior pass (label/project changes do not).
      * Omitted (or xoauth2) → no gate, Connect submits directly as before. Returns the BE `{ ok, error }` verdict.
      * In `edit` mode the pass is NOT required to save (a blank password keeps the
      * current one and the BE reconfigure verifies server-side), but the button
      * still lets the operator confirm a NEW password before saving.
      */
    onTest?: (payload: ConfigureConnectorPayload) => Promise<{ ok: boolean; error?: string | null }>;
    /** Top-level error (e.g. the BE's "IMAP login failed" 422 message). */
    submitError?: string | null;
    /** Per-field validation errors keyed by field name (from a 422 response). */
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
    // ── v8.29 additive props ──────────────────────────────────────────────────
    /** 'create' (default) = Add account (configure). 'edit' = reconfigure connection. */
    mode?: 'create' | 'edit';
    /** Render without the fixed backdrop + title so a parent modal can host it. */
    embedded?: boolean;
    /** Prefill non-secret schema fields (imported config / edit). Secrets ignored. */
    initialValues?: Record<string, string | number | boolean>;
    /** Prefill the account label (create mode only; e.g. from an imported file). */
    initialLabel?: string;
    /** Prefill the project binding (create mode only). */
    initialProjectKey?: string | null;
    /** Override the submit button caption (default: mode-specific). */
    submitLabel?: string;
}

type FieldValue = string | number | boolean;

function initialValue(
    field: CredentialFieldSchema,
    prefill?: Record<string, string | number | boolean>,
): FieldValue {
    // Secrets are NEVER pre-filled — even if a schema (or an imported file) ships a
    // value for a password, the input must render empty (the BE never returns a
    // saved secret either).
    if (field.secret || field.type === 'password') {
        return '';
    }
    // An imported / edit prefill wins over the schema default for non-secret fields.
    if (prefill && Object.prototype.hasOwnProperty.call(prefill, field.name)) {
        const p = prefill[field.name];
        if (field.type === 'checkbox') return p === true || p === 'true' || p === 1;
        return p as FieldValue;
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
    onTest,
    submitError,
    fieldErrors,
    isSubmitting,
    mode = 'create',
    embedded = false,
    initialValues,
    initialLabel = '',
    initialProjectKey = null,
    submitLabel,
}: CredentialConnectorFormProps): ReactNode {
    const schema = useMemo(() => entry.credential_form_schema ?? [], [entry.credential_form_schema]);
    const isEdit = mode === 'edit';
    // In edit mode the label/project live in the Details tab — this panel edits
    // only the connection params + the (optional) secret.
    const showAccountFields = !isEdit;

    // v8.20 — account label (required) + optional project binding. These are NOT
    // schema fields; the host injects them into the configure payload (create only).
    const [label, setLabel] = useState(initialLabel);
    const [projectKey, setProjectKey] = useState(initialProjectKey ?? '');

    // v8.26 — pre-save connection-test state. 'passed' is the only state that
    // lets Connect through (when the gate is active); editing any SCHEMA field
    // (via setValue — NOT the injected label / project binding) resets to 'idle'
    // so params that changed since the pass can't be saved untested.
    const [testStatus, setTestStatus] = useState<'idle' | 'testing' | 'passed' | 'failed'>('idle');
    const [testError, setTestError] = useState<string | null>(null);

    const [values, setValues] = useState<Record<string, FieldValue>>(() => {
        const seed: Record<string, FieldValue> = {};
        for (const field of schema) {
            seed[field.name] = initialValue(field, initialValues);
        }
        return seed;
    });

    // Re-seed if the dialog instance is reused for a different connector / prefill.
    useEffect(() => {
        const seed: Record<string, FieldValue> = {};
        for (const field of schema) {
            seed[field.name] = initialValue(field, initialValues);
        }
        setValues(seed);
    }, [schema, initialValues]);

    // Reset the injected label/project too on connector identity change — so a
    // reused modal instance never leaks a previous account's label/binding into
    // the next submission (the parent also keys this component by connector, so
    // this is belt-and-suspenders).
    useEffect(() => {
        setLabel(initialLabel);
        setProjectKey(initialProjectKey ?? '');
    }, [entry.key, initialLabel, initialProjectKey]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const isVisible = (field: CredentialFieldSchema): boolean =>
        field.showIf === null || values[field.showIf.field] === field.showIf.equals;

    const setValue = (name: string, value: FieldValue) => {
        setValues((prev) => ({ ...prev, [name]: value }));
        // Any change to a connection parameter invalidates a prior successful
        // test: reset the gate so Connect can't save params that differ from the
        // ones that actually passed.
        setTestStatus('idle');
        setTestError(null);
    };

    // The VISIBLE schema field values (an emptied optional is OMITTED, not sent as
    // '' / null, so the BE applies the schema default e.g. port 993). Shared by
    // both the connection test and the final submit so they use identical params.
    const collectFieldValues = (): ConfigureConnectorPayload => {
        const payload: ConfigureConnectorPayload = {};
        for (const field of schema) {
            if (!isVisible(field)) continue;
            const value = values[field.name];
            if (value === '') continue;
            payload[field.name] = value;
        }
        return payload;
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const payload = collectFieldValues();
        if (showAccountFields) {
            // v8.20 — inject the account label (required) + optional project binding.
            // An empty project is OMITTED so the BE applies the tenant default.
            payload.label = label.trim();
            if (projectKey !== '') {
                payload.project_key = projectKey;
            }
        }
        onSubmit(payload);
    };

    const handleTest = async () => {
        if (!onTest) return;

        const payload = collectFieldValues();
        const payloadKey = JSON.stringify(payload);

        setTestStatus('testing');
        setTestError(null);
        try {
            const result = await onTest(payload);

            // If params changed while the request was in-flight, ignore this result:
            // setValue() already reset the gate back to idle.
            if (JSON.stringify(collectFieldValues()) !== payloadKey) {
                return;
            }

            if (result.ok) {
                setTestStatus('passed');
                return;
            }
            setTestStatus('failed');
            setTestError(result.error ?? 'Connection test failed.');
        } catch {
            if (JSON.stringify(collectFieldValues()) !== payloadKey) {
                return;
            }
            // Network / unexpected failure must NOT read as success (R14): show a
            // failed test and keep Connect disabled.
            setTestStatus('failed');
            setTestError('Connection test failed. Please try again.');
        }
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

    // The gate applies only when a tester is wired AND the mode has a synchronous
    // pre-save ping (basic). xoauth2 keeps the old flow (Connect → provider
    // redirect), so no Test button and no gate there.
    const effectiveAuthMode = String(values['auth_mode'] ?? 'basic');
    const testGated = !!onTest && effectiveAuthMode === 'basic';
    // Test needs the visible required fields (host/username/password) — the button
    // is type=button, so native `required` doesn't guard it; gate it here instead.
    // In edit mode `password` is treated as optional for SAVE, but a Test still
    // needs a secret, so require it for the Test button specifically.
    const requiredFieldsFilled = schema
        .filter(isVisible)
        .filter((field) => field.required || (isEdit && (field.secret || field.type === 'password')))
        .every((field) => {
            const v = values[field.name];
            return v !== '' && v !== undefined && v !== null;
        });
    const canTest = testGated && requiredFieldsFilled && testStatus !== 'testing' && !isSubmitting;
    // In edit mode a passing test is NOT required (blank password = keep current,
    // verified server-side on reconfigure); only isSubmitting locks Save.
    const saveDisabled = !!isSubmitting || (!isEdit && testGated && testStatus !== 'passed');
    const resolvedSubmitLabel = submitLabel ?? (isEdit ? 'Save connection' : 'Connect');
    const busyLabel = isEdit ? 'Saving…' : 'Connecting…';

    const dialog = (
        <form
            role={embedded ? undefined : 'dialog'}
            aria-modal={embedded ? undefined : 'true'}
            aria-labelledby={embedded ? undefined : titleId}
            aria-busy={isSubmitting}
            data-testid={`connector-${entry.key}-form`}
            data-mode={mode}
            data-state={isSubmitting ? 'loading' : 'idle'}
            onSubmit={handleSubmit}
            style={
                embedded
                    ? { display: 'flex', flexDirection: 'column', gap: 12, width: '100%' }
                    : {
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
                      }
            }
        >
            {!embedded && (
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    {isEdit ? `Edit ${entry.display_name} connection` : `Connect ${entry.display_name}`}
                </h2>
            )}

            {/* v8.20 — account label + project binding (injected, not schema).
                Hidden in edit mode (the Details tab owns them). */}
            {showAccountFields && (
                <>
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
                </>
            )}

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
                            // Edit mode: the secret is optional (blank = keep current).
                            secretOptional={isEdit}
                            onChange={(v) => setValue(field.name, v)}
                        />
                    ))}
                </div>
            ))}

            {isEdit && (
                <p
                    data-testid={`connector-${entry.key}-form-secret-hint`}
                    style={{ margin: 0, fontSize: 10.5, color: 'var(--fg-3)' }}
                >
                    Leave the password blank to keep the current one.
                </p>
            )}

            {submitError && (
                <p
                    data-testid={`connector-${entry.key}-form-error`}
                    role="alert"
                    style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}
                >
                    {submitError}
                </p>
            )}

            {/* v8.26 — pre-save connection-test feedback (basic mode only). */}
            {testGated && testStatus === 'passed' && (
                <p
                    data-testid={`connector-${entry.key}-form-test-result`}
                    data-status="ok"
                    role="status"
                    style={{ margin: 0, fontSize: 11.5, color: 'var(--ok, #4ade80)' }}
                >
                    ✓ Connection OK{isEdit ? '.' : ' — you can save now.'}
                </p>
            )}
            {testGated && testStatus === 'failed' && (
                <p
                    data-testid={`connector-${entry.key}-form-test-result`}
                    data-status="error"
                    role="alert"
                    style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}
                >
                    {testError}
                </p>
            )}
            {!isEdit && testGated && (testStatus === 'idle' || testStatus === 'testing') && (
                <p
                    data-testid={`connector-${entry.key}-form-test-hint`}
                    style={{ margin: 0, fontSize: 10.5, color: 'var(--fg-3)' }}
                >
                    Test the connection to enable Connect.
                </p>
            )}

            <div
                style={{
                    display: 'flex',
                    gap: 8,
                    justifyContent: testGated ? 'space-between' : 'flex-end',
                    marginTop: 4,
                }}
            >
                {testGated && (
                    <button
                        type="button"
                        data-testid={`connector-${entry.key}-form-test`}
                        onClick={handleTest}
                        disabled={!canTest}
                        aria-busy={testStatus === 'testing'}
                        aria-label="Test connection"
                        style={buttonStyle('secondary', !canTest)}
                    >
                        {testStatus === 'testing' ? 'Testing…' : 'Test connection'}
                    </button>
                )}
                <div style={{ display: 'flex', gap: 8 }}>
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
                        disabled={saveDisabled}
                        aria-disabled={saveDisabled}
                        title={
                            !isEdit && testGated && testStatus !== 'passed'
                                ? 'Test the connection first'
                                : undefined
                        }
                        style={buttonStyle('primary', saveDisabled)}
                    >
                        {isSubmitting ? busyLabel : resolvedSubmitLabel}
                    </button>
                </div>
            </div>
        </form>
    );

    if (embedded) {
        return dialog;
    }

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
            {dialog}
        </div>
    );
}

interface FieldProps {
    connectorKey: string;
    field: CredentialFieldSchema;
    value: FieldValue;
    error?: string;
    /** Edit mode: a secret field is optional (blank = keep the current one). */
    secretOptional?: boolean;
    onChange: (value: FieldValue) => void;
}

function Field({ connectorKey, field, value, error, secretOptional, onChange }: FieldProps): ReactNode {
    const id = `connector-${connectorKey}-form-${field.name}`;
    const testid = id;
    const isSecret = field.secret || field.type === 'password';
    // A required secret becomes optional in edit mode (blank = keep current).
    const required = field.required && !(isSecret && secretOptional);

    return (
        <label htmlFor={id} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>
                {field.label}
                {required && <span style={{ color: 'var(--err, #fca5a5)' }}> *</span>}
            </span>

            {field.type === 'select' ? (
                <select
                    id={id}
                    data-testid={testid}
                    required={required}
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
                    required={required}
                    // Secrets are never pre-filled; the BE never returns a saved value.
                    autoComplete={isSecret ? 'new-password' : 'off'}
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
                    placeholder={
                        isSecret && secretOptional ? 'Leave blank to keep current' : (field.help ?? undefined)
                    }
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
