import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import type { AdminTag, CreateTagPayload, UpdateTagPayload } from './admin-tags.api';

/**
 * T2.10 — modal form for creating or editing a tag.
 *
 * Single component handles both modes:
 *   - `tag === null`  → create mode (`project_key`, `slug`, `label`,
 *                       `color` all editable + required where appropriate)
 *   - `tag === <obj>` → edit mode (`project_key` is read-only — moving
 *                       a tag between projects would orphan pivot rows;
 *                       BE rejects with 422, FE preempts the UX)
 *
 * Submission shape:
 *   - create: emits `CreateTagPayload` to `onSubmit`
 *   - edit:   emits `UpdateTagPayload` (only the changed fields)
 *
 * R11: every interactive element carries a `data-testid`.
 * R15: every input has a bound `<label htmlFor>`. Form is `role="dialog"`
 * + `aria-modal="true"`. Esc closes via the keydown listener below.
 */

export interface TagFormDialogProps {
    /** Null = create mode; AdminTag = edit mode. */
    tag: AdminTag | null;
    /** Pre-filled project_key for create mode (e.g. when invoked from a project-scoped view). */
    defaultProjectKey?: string;
    /** Submit handler — caller maps to api.create/api.update + cache invalidation. */
    onSubmit: (payload: CreateTagPayload | UpdateTagPayload) => void;
    onClose: () => void;
    /** Inline error message from the BE (e.g. duplicate-slug 422). */
    submitError?: string | null;
    /** Loading state (mutation in flight). */
    isSubmitting?: boolean;
}

export function TagFormDialog({
    tag,
    defaultProjectKey,
    onSubmit,
    onClose,
    submitError,
    isSubmitting,
}: TagFormDialogProps): ReactNode {
    const isEdit = tag !== null;
    const [projectKey, setProjectKey] = useState(tag?.project_key ?? defaultProjectKey ?? '');
    const [slug, setSlug] = useState(tag?.slug ?? '');
    const [label, setLabel] = useState(tag?.label ?? '');
    const [color, setColor] = useState(tag?.color ?? '');

    // Keep local state in sync if the same dialog is reused for a
    // different tag (rare, but the parent might recycle the instance).
    useEffect(() => {
        setProjectKey(tag?.project_key ?? defaultProjectKey ?? '');
        setSlug(tag?.slug ?? '');
        setLabel(tag?.label ?? '');
        setColor(tag?.color ?? '');
    }, [tag, defaultProjectKey]);

    // Esc closes the dialog. Click-outside is handled by the parent
    // (it owns the backdrop), so the dialog itself doesn't need it.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (isEdit) {
            // Only send fields that changed — the BE accepts `sometimes`
            // rules so missing keys are fine. project_key intentionally
            // never goes on the wire in edit mode.
            const payload: UpdateTagPayload = {};
            if (slug !== tag.slug) payload.slug = slug;
            if (label !== tag.label) payload.label = label;
            const normalizedColor = color === '' ? null : color;
            if (normalizedColor !== tag.color) payload.color = normalizedColor;
            onSubmit(payload);
        } else {
            const payload: CreateTagPayload = {
                project_key: projectKey,
                slug,
                label,
                color: color === '' ? null : color,
            };
            onSubmit(payload);
        }
    };

    return (
        <div
            data-testid="admin-tag-form-backdrop"
            onClick={(e) => {
                // Only close when clicking the backdrop itself, not the
                // dialog content bubbling up.
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
                aria-labelledby="admin-tag-form-title"
                data-testid="admin-tag-form"
                data-mode={isEdit ? 'edit' : 'create'}
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
                <h2
                    id="admin-tag-form-title"
                    style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}
                >
                    {isEdit ? `Edit tag: ${tag.label}` : 'Create tag'}
                </h2>

                <label htmlFor="admin-tag-project" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>Project key</span>
                    <input
                        id="admin-tag-project"
                        data-testid="admin-tag-form-project"
                        type="text"
                        required
                        readOnly={isEdit}
                        value={projectKey}
                        onChange={(e) => setProjectKey(e.target.value)}
                        placeholder="hr-portal"
                        maxLength={120}
                        style={inputStyle(isEdit)}
                    />
                    {isEdit && (
                        <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                            Project key cannot be changed. Delete + recreate to move a tag across projects.
                        </span>
                    )}
                </label>

                <label htmlFor="admin-tag-slug" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>Slug</span>
                    <input
                        id="admin-tag-slug"
                        data-testid="admin-tag-form-slug"
                        type="text"
                        required
                        pattern="^[a-z0-9]+(?:-[a-z0-9]+)*$"
                        title="Lowercase letters, digits, and hyphens only"
                        value={slug}
                        onChange={(e) => setSlug(e.target.value)}
                        placeholder="policy"
                        maxLength={120}
                        style={inputStyle(false)}
                    />
                </label>

                <label htmlFor="admin-tag-label" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>Label</span>
                    <input
                        id="admin-tag-label"
                        data-testid="admin-tag-form-label"
                        type="text"
                        required
                        value={label}
                        onChange={(e) => setLabel(e.target.value)}
                        placeholder="Policy"
                        maxLength={120}
                        style={inputStyle(false)}
                    />
                </label>

                <label htmlFor="admin-tag-color" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>Color (optional)</span>
                    <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                        <input
                            id="admin-tag-color"
                            data-testid="admin-tag-form-color"
                            type="color"
                            value={color || '#888888'}
                            onChange={(e) => setColor(e.target.value)}
                            style={{ width: 36, height: 30, borderRadius: 6, border: '1px solid var(--panel-border)', cursor: 'pointer', background: 'transparent' }}
                        />
                        <input
                            data-testid="admin-tag-form-color-text"
                            type="text"
                            value={color}
                            onChange={(e) => setColor(e.target.value)}
                            placeholder="#1a2b3c (or leave empty)"
                            pattern="^#[0-9a-fA-F]{6}$|^$"
                            style={{ ...inputStyle(false), flex: 1, fontFamily: 'var(--font-mono, monospace)' }}
                        />
                        {color && (
                            <button
                                type="button"
                                data-testid="admin-tag-form-color-clear"
                                aria-label="Clear color"
                                onClick={() => setColor('')}
                                style={{
                                    border: 0,
                                    background: 'transparent',
                                    color: 'var(--fg-3)',
                                    cursor: 'pointer',
                                    fontSize: 12,
                                    padding: '2px 6px',
                                }}
                            >
                                ×
                            </button>
                        )}
                    </div>
                </label>

                {submitError && (
                    <p
                        data-testid="admin-tag-form-error"
                        role="alert"
                        style={{ margin: 0, fontSize: 11.5, color: 'var(--err)' }}
                    >
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="admin-tag-form-cancel"
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid="admin-tag-form-submit"
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
