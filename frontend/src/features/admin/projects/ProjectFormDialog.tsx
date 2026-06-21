import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import type { AdminProject, CreateProjectPayload, UpdateProjectPayload } from './admin-projects.api';

/**
 * v8.9 — modal form for creating or editing a project.
 *
 *   - `project === null` → create mode: Name (required), Project key
 *     (auto-slugged from the name as you type, but editable until you
 *     stop touching it) and Description (optional).
 *   - `project === <obj>` → edit mode: Name + Description only; the key is
 *     read-only (immutable join key — the BE 422s a change, the FE
 *     preempts the UX).
 *
 * R11: every interactive element carries a `data-testid`. R15: every input
 * has a bound `<label htmlFor>`; the form is `role="dialog"` +
 * `aria-modal="true"`; Esc closes via the keydown listener.
 */

export interface ProjectFormDialogProps {
    project: AdminProject | null;
    onSubmit: (payload: CreateProjectPayload | UpdateProjectPayload) => void;
    onClose: () => void;
    submitError?: string | null;
    isSubmitting?: boolean;
}

/** Mirror of the BE Str::slug shape (preview only — the BE re-slugs). */
function slugify(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export function ProjectFormDialog({
    project,
    onSubmit,
    onClose,
    submitError,
    isSubmitting,
}: ProjectFormDialogProps): ReactNode {
    const isEdit = project !== null;
    const [name, setName] = useState(project?.name ?? '');
    const [projectKey, setProjectKey] = useState(project?.project_key ?? '');
    const [description, setDescription] = useState(project?.description ?? '');
    // While false, the key mirrors slugify(name); once the user edits the
    // key by hand we stop overwriting it.
    const [keyTouched, setKeyTouched] = useState(isEdit);

    useEffect(() => {
        setName(project?.name ?? '');
        setProjectKey(project?.project_key ?? '');
        setDescription(project?.description ?? '');
        setKeyTouched(project !== null);
    }, [project]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const handleNameChange = (value: string) => {
        setName(value);
        if (!isEdit && !keyTouched) {
            setProjectKey(slugify(value));
        }
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (isEdit) {
            const payload: UpdateProjectPayload = {};
            if (name !== project.name) payload.name = name;
            const normalizedDesc = description === '' ? null : description;
            if (normalizedDesc !== project.description) payload.description = normalizedDesc;
            onSubmit(payload);
            return;
        }
        const payload: CreateProjectPayload = {
            name,
            project_key: projectKey,
            description: description === '' ? null : description,
        };
        onSubmit(payload);
    };

    return (
        <div
            data-testid="admin-project-form-backdrop"
            onClick={(e) => {
                if (e.target === e.currentTarget) {
                    onClose();
                }
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
                aria-labelledby="admin-project-form-title"
                data-testid="admin-project-form"
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
                <h2 id="admin-project-form-title" style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    {isEdit ? `Edit project: ${project.name}` : 'Create project'}
                </h2>

                <label htmlFor="admin-project-name" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>Name</span>
                    <input
                        id="admin-project-name"
                        data-testid="admin-project-form-name"
                        type="text"
                        required
                        value={name}
                        onChange={(e) => handleNameChange(e.target.value)}
                        placeholder="Surface KB"
                        maxLength={200}
                        style={inputStyle(false)}
                    />
                </label>

                <label htmlFor="admin-project-key" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>Project key</span>
                    <input
                        id="admin-project-key"
                        data-testid="admin-project-form-key"
                        type="text"
                        required
                        readOnly={isEdit}
                        value={projectKey}
                        onChange={(e) => {
                            setKeyTouched(true);
                            setProjectKey(e.target.value);
                        }}
                        placeholder="surface-kb"
                        pattern="^[a-z0-9]+(?:-[a-z0-9]+)*$"
                        title="Lowercase letters, digits, and hyphens only"
                        maxLength={120}
                        style={inputStyle(isEdit)}
                    />
                    <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                        {isEdit
                            ? 'The key is immutable — it joins documents, memberships and chats to this project.'
                            : 'Auto-filled from the name; edit if you want a different key. Immutable after creation.'}
                    </span>
                </label>

                <label htmlFor="admin-project-description" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>Description (optional)</span>
                    <textarea
                        id="admin-project-description"
                        data-testid="admin-project-form-description"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        placeholder="What this project groups together…"
                        maxLength={2000}
                        rows={3}
                        style={{ ...inputStyle(false), resize: 'vertical', fontFamily: 'inherit' }}
                    />
                </label>

                {submitError && (
                    <p data-testid="admin-project-form-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err)' }}>
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="admin-project-form-cancel"
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid="admin-project-form-submit"
                        disabled={isSubmitting}
                        style={buttonStyle('primary', !!isSubmitting)}
                    >
                        {isSubmitting ? 'Saving…' : isEdit ? 'Save' : 'Create'}
                    </button>
                </div>
            </form>
        </div>
    );
}

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
