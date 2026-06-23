import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import type { AdminProject } from '../projects/admin-projects.api';

/**
 * v8.20 — modal for an account's METADATA: its `label` (account discriminator)
 * and its optional KB `project` binding. Reused for two flows:
 *   - add-oauth : name a new OAuth account before the provider redirect.
 *   - edit      : rename / rebind an existing account (PATCH).
 *
 * The project dropdown derives its options from the REAL project registry
 * (R18 — never a hard-coded subset) plus a "Global (tenant default)" sentinel
 * (empty value) that inherits `kb.ingest.default_project`.
 *
 * R11/R29: stable testids `connector-{key}-account-form-{field}`.
 * R15: every control has a bound `<label htmlFor>`; dialog is role=dialog +
 *      aria-modal; Esc closes.
 */

export interface AccountMetaFormValues {
    label: string;
    /** '' = Global (tenant default). */
    projectKey: string;
}

export interface AccountMetaFormProps {
    connectorKey: string;
    title: string;
    submitLabel: string;
    projects: AdminProject[];
    initialLabel?: string;
    initialProjectKey?: string | null;
    /** Lock the label field (edit flows that shouldn't rename — unused for now). */
    labelReadOnly?: boolean;
    onSubmit: (values: AccountMetaFormValues) => void;
    onClose: () => void;
    submitError?: string | null;
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
}

export function AccountMetaForm({
    connectorKey,
    title,
    submitLabel,
    projects,
    initialLabel = '',
    initialProjectKey = null,
    labelReadOnly = false,
    onSubmit,
    onClose,
    submitError,
    fieldErrors,
    isSubmitting,
}: AccountMetaFormProps): ReactNode {
    const [label, setLabel] = useState(initialLabel);
    const [projectKey, setProjectKey] = useState(initialProjectKey ?? '');

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        onSubmit({ label: label.trim(), projectKey });
    };

    const titleId = `connector-${connectorKey}-account-form-title`;
    const labelId = `connector-${connectorKey}-account-form-label`;
    const projectId = `connector-${connectorKey}-account-form-project`;

    return (
        <div
            data-testid={`connector-${connectorKey}-account-form-backdrop`}
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
                data-testid={`connector-${connectorKey}-account-form`}
                data-state={isSubmitting ? 'loading' : 'idle'}
                onSubmit={handleSubmit}
                style={{
                    background: 'var(--panel-solid, #1a1a22)',
                    border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
                    borderRadius: 12,
                    boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.4))',
                    minWidth: 360,
                    maxWidth: 440,
                    padding: 16,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                }}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    {title}
                </h2>

                <label htmlFor={labelId} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>
                        Account label<span style={{ color: 'var(--err, #fca5a5)' }}> *</span>
                    </span>
                    <input
                        id={labelId}
                        data-testid={labelId}
                        type="text"
                        required
                        // At least one non-whitespace char — native validation
                        // matches the trimmed submission so a whitespace-only
                        // label can't slip through to an avoidable 422. JS-string
                        // expression so the DOM receives a literal `\S` regardless
                        // of JSX string-escape interpretation.
                        pattern={'.*\\S.*'}
                        readOnly={labelReadOnly}
                        value={label}
                        onChange={(e) => setLabel(e.target.value)}
                        placeholder="e.g. Support, Sales"
                        style={inputStyle()}
                    />
                    {fieldErrors?.label && (
                        <span
                            data-testid={`${labelId}-error`}
                            role="alert"
                            style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}
                        >
                            {fieldErrors.label}
                        </span>
                    )}
                </label>

                <label htmlFor={projectId} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>KB project binding</span>
                    <select
                        id={projectId}
                        data-testid={projectId}
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
                            data-testid={`${projectId}-error`}
                            role="alert"
                            style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}
                        >
                            {fieldErrors.project_key}
                        </span>
                    )}
                </label>

                {submitError && (
                    <p
                        data-testid={`connector-${connectorKey}-account-form-error`}
                        role="alert"
                        style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}
                    >
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid={`connector-${connectorKey}-account-form-cancel`}
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid={`connector-${connectorKey}-account-form-submit`}
                        disabled={isSubmitting}
                        style={buttonStyle('primary', !!isSubmitting)}
                    >
                        {isSubmitting ? 'Saving…' : submitLabel}
                    </button>
                </div>
            </form>
        </div>
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
