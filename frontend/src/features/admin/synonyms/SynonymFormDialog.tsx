import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import {
    normalizeToken,
    parseSynonyms,
    type AdminSynonym,
    type CreateSynonymPayload,
    type UpdateSynonymPayload,
} from './synonyms.api';

/**
 * v8.7/W1 — modal form for creating or editing a synonym group.
 *
 *   - `synonym === null`  → create mode (project_key + term + synonyms)
 *   - `synonym === <obj>` → edit mode (project_key read-only — a group's
 *                           identity is its (project, term) pair; BE
 *                           rejects a project change with 422)
 *
 * R11: every interactive element carries a `data-testid`.
 * R15: every input has a bound `<label htmlFor>`. Form is `role="dialog"`
 * + `aria-modal="true"`. Esc closes via the keydown listener.
 */

export interface SynonymFormDialogProps {
    synonym: AdminSynonym | null;
    defaultProjectKey?: string;
    onSubmit: (payload: CreateSynonymPayload | UpdateSynonymPayload) => void;
    onClose: () => void;
    submitError?: string | null;
    isSubmitting?: boolean;
}

export function SynonymFormDialog({
    synonym,
    defaultProjectKey,
    onSubmit,
    onClose,
    submitError,
    isSubmitting,
}: SynonymFormDialogProps): ReactNode {
    const isEdit = synonym !== null;
    const [projectKey, setProjectKey] = useState(synonym?.project_key ?? defaultProjectKey ?? '');
    const [term, setTerm] = useState(synonym?.term ?? '');
    const [synonymsText, setSynonymsText] = useState((synonym?.synonyms ?? []).join('\n'));
    const [enabled, setEnabled] = useState(synonym?.enabled ?? true);

    useEffect(() => {
        setProjectKey(synonym?.project_key ?? defaultProjectKey ?? '');
        setTerm(synonym?.term ?? '');
        setSynonymsText((synonym?.synonyms ?? []).join('\n'));
        setEnabled(synonym?.enabled ?? true);
    }, [synonym, defaultProjectKey]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    // Normalize the term exactly as the backend does (lowercase + trim +
    // collapse whitespace) so the distinct-check and persisted value agree
    // with the server — otherwise a whitespace-only difference would pass
    // the client check and 422 server-side.
    const normTerm = normalizeToken(term);
    const parsed = parseSynonyms(synonymsText);
    const distinct = parsed.filter((s) => s !== normTerm);
    const canSubmit = normTerm !== '' && distinct.length > 0 && (isEdit || projectKey.trim() !== '');

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (isEdit) {
            const payload: UpdateSynonymPayload = {};
            if (normTerm !== synonym.term) payload.term = normTerm;
            // Always send synonyms on edit — the textarea is the source of
            // truth for the whole list.
            payload.synonyms = distinct;
            if (enabled !== synonym.enabled) payload.enabled = enabled;
            onSubmit(payload);
        } else {
            onSubmit({
                project_key: projectKey.trim(),
                term: normTerm,
                synonyms: distinct,
                enabled,
            });
        }
    };

    return (
        <div
            data-testid="admin-synonym-form-backdrop"
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
                aria-labelledby="admin-synonym-form-title"
                data-testid="admin-synonym-form"
                data-mode={isEdit ? 'edit' : 'create'}
                onSubmit={handleSubmit}
                style={{
                    background: 'var(--panel-solid, #1a1a22)',
                    border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
                    borderRadius: 12,
                    boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.4))',
                    minWidth: 380,
                    maxWidth: 460,
                    padding: 16,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                }}
            >
                <h2 id="admin-synonym-form-title" style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    {isEdit ? `Edit synonym group: ${synonym.term}` : 'Create synonym group'}
                </h2>

                <label htmlFor="admin-synonym-project" style={fieldStyle}>
                    <span style={labelStyle}>Project key</span>
                    <input
                        id="admin-synonym-project"
                        data-testid="admin-synonym-form-project"
                        type="text"
                        required
                        readOnly={isEdit}
                        value={projectKey}
                        onChange={(e) => setProjectKey(e.target.value)}
                        placeholder="engineering"
                        maxLength={120}
                        style={inputStyle(isEdit)}
                    />
                    {isEdit && (
                        <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                            Project key cannot be changed. Delete + recreate to move a group across projects.
                        </span>
                    )}
                </label>

                <label htmlFor="admin-synonym-term" style={fieldStyle}>
                    <span style={labelStyle}>Term (anchor)</span>
                    <input
                        id="admin-synonym-term"
                        data-testid="admin-synonym-form-term"
                        type="text"
                        required
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder="k8s"
                        maxLength={200}
                        style={inputStyle(false)}
                    />
                </label>

                <label htmlFor="admin-synonym-synonyms" style={fieldStyle}>
                    <span style={labelStyle}>Synonyms (one per line, or comma-separated)</span>
                    <textarea
                        id="admin-synonym-synonyms"
                        data-testid="admin-synonym-form-synonyms"
                        required
                        value={synonymsText}
                        onChange={(e) => setSynonymsText(e.target.value)}
                        placeholder={'kubernetes\ncontainer orchestration'}
                        rows={4}
                        style={{ ...inputStyle(false), resize: 'vertical', fontFamily: 'var(--font-mono, monospace)' }}
                    />
                    <span data-testid="admin-synonym-form-preview" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                        {distinct.length > 0
                            ? `${distinct.length} synonym${distinct.length === 1 ? '' : 's'}: ${distinct.join(', ')}`
                            : 'Add at least one synonym different from the term.'}
                    </span>
                </label>

                <label
                    htmlFor="admin-synonym-enabled"
                    style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--fg-1)', fontSize: 12 }}
                >
                    <input
                        id="admin-synonym-enabled"
                        data-testid="admin-synonym-form-enabled"
                        type="checkbox"
                        checked={enabled}
                        onChange={(e) => setEnabled(e.target.checked)}
                    />
                    Enabled (expand queries with this group)
                </label>

                {submitError && (
                    <p data-testid="admin-synonym-form-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err)' }}>
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="admin-synonym-form-cancel"
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid="admin-synonym-form-submit"
                        disabled={isSubmitting || !canSubmit}
                        style={buttonStyle('primary', !!isSubmitting || !canSubmit)}
                    >
                        {isSubmitting ? 'Saving…' : isEdit ? 'Save' : 'Create'}
                    </button>
                </div>
            </form>
        </div>
    );
}

const fieldStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4 };
const labelStyle: React.CSSProperties = { color: 'var(--fg-2)', fontSize: 11 };

function inputStyle(disabled: boolean): React.CSSProperties {
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
