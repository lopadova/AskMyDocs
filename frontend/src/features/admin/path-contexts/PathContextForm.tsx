import { useEffect, useId, useState, type CSSProperties, type FormEvent, type ReactNode } from 'react';
import {
    DESCRIPTION_MAX,
    PATH_PREFIX_MAX,
    validatePathContext,
    type PathContext,
    type PathContextField,
    type PathContextFieldErrors,
    type UpsertPathContextPayload,
} from './path-contexts.api';

/**
 * Add / edit form for a path context.
 *
 *   - `context === null`  → add mode: project select + prefix + description
 *   - `context === <row>` → edit mode: a context's identity is its
 *     `(project_key, path_prefix)` pair (the backend upserts on it), so
 *     project and prefix are read-only and only the description changes.
 *
 * R11: stable `data-testid`s on every actionable element.
 * R15: every control has a bound `<label htmlFor>`; errors are linked via
 * `aria-describedby` + `aria-invalid` and announced with `role="alert"`.
 * Validation runs client-side (mirror of the backend rules) AND the server's
 * 422 field errors render in the very same `<field>-error` slots.
 */

export interface PathContextFormProps {
    context: PathContext | null;
    projectOptions: string[];
    defaultProjectKey?: string;
    onSubmit: (payload: UpsertPathContextPayload) => void;
    onCancel: () => void;
    /** Field errors from a 422 response. */
    serverErrors?: PathContextFieldErrors;
    /** Non-field failure (500, network, …). */
    submitError?: string | null;
    isSubmitting?: boolean;
}

const FIELD_TESTID: Record<PathContextField, string> = {
    project_key: 'path-context-project-error',
    path_prefix: 'path-context-prefix-error',
    description: 'path-context-description-error',
};

export function PathContextForm({
    context,
    projectOptions,
    defaultProjectKey,
    onSubmit,
    onCancel,
    serverErrors,
    submitError,
    isSubmitting,
}: PathContextFormProps): ReactNode {
    const uid = useId();
    const isEdit = context !== null;
    const [projectKey, setProjectKey] = useState(context?.project_key ?? defaultProjectKey ?? '');
    const [pathPrefix, setPathPrefix] = useState(context?.path_prefix ?? '');
    const [description, setDescription] = useState(context?.description ?? '');
    const [clientErrors, setClientErrors] = useState<PathContextFieldErrors>({});

    useEffect(() => {
        setProjectKey(context?.project_key ?? defaultProjectKey ?? '');
        setPathPrefix(context?.path_prefix ?? '');
        setDescription(context?.description ?? '');
        setClientErrors({});
    }, [context, defaultProjectKey]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onCancel();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onCancel]);

    // R18 — options come from the projects API; an edited row whose project
    // is not (or no longer) in that list must still be selectable.
    const options = projectKey !== '' && !projectOptions.includes(projectKey)
        ? [projectKey, ...projectOptions]
        : projectOptions;

    const errorFor = (field: PathContextField): string | undefined => clientErrors[field] ?? serverErrors?.[field];
    const clearClientError = (field: PathContextField) =>
        setClientErrors((prev) => (prev[field] === undefined ? prev : { ...prev, [field]: undefined }));

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const payload: UpsertPathContextPayload = {
            project_key: projectKey.trim(),
            path_prefix: pathPrefix.trim(),
            description: description.trim(),
        };
        const errors = validatePathContext(payload);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        onSubmit(payload);
    };

    const projectError = errorFor('project_key');
    const prefixError = errorFor('path_prefix');
    const descriptionError = errorFor('description');
    const overLimit = description.length > DESCRIPTION_MAX;

    return (
        <form
            data-testid="path-context-form"
            data-mode={isEdit ? 'edit' : 'create'}
            data-state={isSubmitting ? 'loading' : 'idle'}
            aria-labelledby={`${uid}-title`}
            aria-busy={isSubmitting ? 'true' : 'false'}
            noValidate
            onSubmit={handleSubmit}
            style={formStyle}
        >
            <h2 id={`${uid}-title`} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                {isEdit ? `Edit path context: ${context.path_prefix}` : 'Add path context'}
            </h2>

            <div style={fieldStyle}>
                <label htmlFor={`${uid}-project`} style={labelStyle}>
                    Project
                </label>
                <select
                    id={`${uid}-project`}
                    data-testid="path-context-project"
                    value={projectKey}
                    disabled={isEdit}
                    aria-invalid={projectError ? 'true' : 'false'}
                    aria-describedby={projectError ? `${uid}-project-error` : undefined}
                    onChange={(e) => {
                        setProjectKey(e.target.value);
                        clearClientError('project_key');
                    }}
                    style={inputStyle(isEdit)}
                >
                    <option value="">Select a project…</option>
                    {options.map((key) => (
                        <option key={key} value={key}>
                            {key}
                        </option>
                    ))}
                </select>
                {projectError && (
                    <FieldError id={`${uid}-project-error`} testid={FIELD_TESTID.project_key} message={projectError} />
                )}
            </div>

            <div style={fieldStyle}>
                <label htmlFor={`${uid}-prefix`} style={labelStyle}>
                    Path prefix
                </label>
                <input
                    id={`${uid}-prefix`}
                    data-testid="path-context-prefix"
                    type="text"
                    value={pathPrefix}
                    readOnly={isEdit}
                    placeholder="policies/"
                    autoComplete="off"
                    spellCheck={false}
                    aria-invalid={prefixError ? 'true' : 'false'}
                    aria-describedby={`${uid}-prefix-hint${prefixError ? ` ${uid}-prefix-error` : ''}`}
                    onChange={(e) => {
                        setPathPrefix(e.target.value);
                        clearClientError('path_prefix');
                    }}
                    style={{ ...inputStyle(isEdit), fontFamily: 'var(--font-mono, monospace)' }}
                />
                <span id={`${uid}-prefix-hint`} style={hintStyle}>
                    {isEdit
                        ? 'Project and prefix identify the context and cannot be changed. Delete and re-add to move it.'
                        : `Relative to the project root, no leading slash, up to ${PATH_PREFIX_MAX} characters.`}
                </span>
                {prefixError && (
                    <FieldError id={`${uid}-prefix-error`} testid={FIELD_TESTID.path_prefix} message={prefixError} />
                )}
            </div>

            <div style={fieldStyle}>
                <label htmlFor={`${uid}-description`} style={labelStyle}>
                    Description
                </label>
                <textarea
                    id={`${uid}-description`}
                    data-testid="path-context-description"
                    value={description}
                    rows={4}
                    placeholder="What this part of the corpus is, e.g. current HR policies approved by the DPO."
                    aria-invalid={descriptionError ? 'true' : 'false'}
                    aria-describedby={`${uid}-description-count${descriptionError ? ` ${uid}-description-error` : ''}`}
                    onChange={(e) => {
                        setDescription(e.target.value);
                        clearClientError('description');
                    }}
                    style={{ ...inputStyle(false), resize: 'vertical' }}
                />
                <span
                    id={`${uid}-description-count`}
                    data-testid="path-context-description-count"
                    data-over-limit={overLimit ? 'true' : 'false'}
                    style={{ ...hintStyle, textAlign: 'right', color: overLimit ? 'var(--err)' : 'var(--fg-3)' }}
                >
                    {description.length} / {DESCRIPTION_MAX}
                </span>
                {descriptionError && (
                    <FieldError
                        id={`${uid}-description-error`}
                        testid={FIELD_TESTID.description}
                        message={descriptionError}
                    />
                )}
            </div>

            {submitError && (
                <p data-testid="path-context-form-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err)' }}>
                    {submitError}
                </p>
            )}

            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                <button
                    type="button"
                    data-testid="path-context-cancel"
                    onClick={onCancel}
                    disabled={isSubmitting}
                    style={buttonStyle('secondary', !!isSubmitting)}
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    data-testid="path-context-save"
                    disabled={isSubmitting}
                    style={buttonStyle('primary', !!isSubmitting)}
                >
                    {isSubmitting ? 'Saving…' : 'Save'}
                </button>
            </div>
        </form>
    );
}

function FieldError({ id, testid, message }: { id: string; testid: string; message: string }): ReactNode {
    return (
        <span id={id} data-testid={testid} role="alert" style={{ fontSize: 11.5, color: 'var(--err)' }}>
            {message}
        </span>
    );
}

const formStyle: CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 12,
    maxWidth: 520,
    marginBottom: 20,
    padding: 16,
    borderRadius: 10,
    border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
    background: 'var(--panel-solid, #1a1a22)',
};
const fieldStyle: CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4 };
const labelStyle: CSSProperties = { color: 'var(--fg-2)', fontSize: 11 };
const hintStyle: CSSProperties = { fontSize: 10.5, color: 'var(--fg-3)' };

function inputStyle(disabled: boolean): CSSProperties {
    return {
        padding: '5px 8px',
        borderRadius: 6,
        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
        background: 'var(--bg-3, rgba(255,255,255,.04))',
        color: disabled ? 'var(--fg-3)' : 'var(--fg-0)',
        fontSize: 12,
        opacity: disabled ? 0.7 : 1,
    };
}

function buttonStyle(variant: 'primary' | 'secondary', disabled: boolean): CSSProperties {
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
