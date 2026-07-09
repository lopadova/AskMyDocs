import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import type { AdminTeam, CreateTeamPayload, UpdateTeamPayload } from './admin-teams.api';

/**
 * v8.28 — modal form for creating or renaming a team (= tenant).
 *
 *   - `team === null` → create mode: Name (required) + Slug (auto-slugged
 *     from the name as you type, editable until you touch it). The slug is
 *     the tenant_id — immutable once created.
 *   - `team === <obj>` → rename mode: Name only; the slug is read-only.
 *
 * R11: every interactive element carries a `data-testid`. R15: every input
 * has a bound `<label htmlFor>`; the form is `role="dialog"` +
 * `aria-modal="true"`; Esc closes via the keydown listener. Per-field errors
 * render next to their input as `admin-team-form-<field>-error`.
 */

export interface TeamFormDialogProps {
    team: AdminTeam | null;
    onSubmit: (payload: CreateTeamPayload | UpdateTeamPayload) => void;
    onClose: () => void;
    submitError?: string | null;
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
}

/** Mirror of the BE Str::slug shape (preview only — the BE re-slugs). */
function slugify(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9_-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export function TeamFormDialog({
    team,
    onSubmit,
    onClose,
    submitError,
    fieldErrors,
    isSubmitting,
}: TeamFormDialogProps): ReactNode {
    const isEdit = team !== null;
    const [name, setName] = useState(team?.name ?? '');
    const [slug, setSlug] = useState(team?.slug ?? '');
    // While false, the slug mirrors slugify(name); once the user edits the
    // slug by hand we stop overwriting it.
    const [slugTouched, setSlugTouched] = useState(isEdit);

    useEffect(() => {
        setName(team?.name ?? '');
        setSlug(team?.slug ?? '');
        setSlugTouched(team !== null);
    }, [team]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const handleNameChange = (value: string) => {
        setName(value);
        if (!isEdit && !slugTouched) {
            setSlug(slugify(value));
        }
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (isEdit) {
            onSubmit({ name });
            return;
        }
        onSubmit({ name, slug });
    };

    return (
        <div
            data-testid="admin-team-form-backdrop"
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
                aria-labelledby="admin-team-form-title"
                data-testid="admin-team-form"
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
                <h2 id="admin-team-form-title" style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    {isEdit ? `Rename team: ${team.name}` : 'Create team'}
                </h2>

                <label htmlFor="admin-team-name" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>Name</span>
                    <input
                        id="admin-team-name"
                        data-testid="admin-team-form-name"
                        type="text"
                        required
                        value={name}
                        onChange={(e) => handleNameChange(e.target.value)}
                        placeholder="Acme Corp"
                        maxLength={200}
                        style={inputStyle(false)}
                    />
                    {fieldErrors?.name && (
                        <span
                            data-testid="admin-team-form-name-error"
                            role="alert"
                            style={{ fontSize: 10.5, color: 'var(--err)' }}
                        >
                            {fieldErrors.name}
                        </span>
                    )}
                </label>

                <label htmlFor="admin-team-slug" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>Slug</span>
                    <input
                        id="admin-team-slug"
                        data-testid="admin-team-form-slug"
                        type="text"
                        required
                        readOnly={isEdit}
                        value={slug}
                        onChange={(e) => {
                            setSlugTouched(true);
                            setSlug(e.target.value);
                        }}
                        placeholder="acme-corp"
                        pattern="^[a-z0-9_-]{1,50}$"
                        title="Lowercase letters, digits, hyphens and underscores only"
                        maxLength={50}
                        style={inputStyle(isEdit)}
                    />
                    {fieldErrors?.slug && (
                        <span
                            data-testid="admin-team-form-slug-error"
                            role="alert"
                            style={{ fontSize: 10.5, color: 'var(--err)' }}
                        >
                            {fieldErrors.slug}
                        </span>
                    )}
                    <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                        {isEdit
                            ? 'The slug is the tenant id — immutable; every document, member and chat joins to it.'
                            : 'Auto-filled from the name; edit if you want a different id. Immutable after creation.'}
                    </span>
                </label>

                {submitError && (
                    <p data-testid="admin-team-form-error" role="alert" style={{ margin: 0, fontSize: 11.5, color: 'var(--err)' }}>
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid="admin-team-form-cancel"
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid="admin-team-form-submit"
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
