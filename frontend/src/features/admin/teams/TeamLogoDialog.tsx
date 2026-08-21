import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { useMutation } from '@tanstack/react-query';
import { adminTeamsApi, type AdminTeam } from './admin-teams.api';
import { toAdminError } from '../shared/errors';

interface TeamLogoDialogProps {
    team: AdminTeam;
    onClose: () => void;
    onChanged: () => void | Promise<void>;
}

export function TeamLogoDialog({ team, onClose, onChanged }: TeamLogoDialogProps): ReactNode {
    const [file, setFile] = useState<File | null>(null);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (file === null) {
            setPreviewUrl(null);
            return;
        }
        const next = URL.createObjectURL(file);
        setPreviewUrl(next);
        return () => URL.revokeObjectURL(next);
    }, [file]);

    const upload = useMutation({
        mutationFn: () => {
            if (file === null) throw new Error('Choose a logo first.');
            return adminTeamsApi.uploadLogo(team.slug, file);
        },
        onSuccess: async () => {
            await onChanged();
            onClose();
        },
        onError: (cause) => setError(toAdminError(cause).message),
    });

    const remove = useMutation({
        mutationFn: () => adminTeamsApi.deleteLogo(team.slug),
        onSuccess: async () => {
            await onChanged();
            onClose();
        },
        onError: (cause) => setError(toAdminError(cause).message),
    });

    const busy = upload.isPending || remove.isPending;
    const state = busy ? 'loading' : error ? 'error' : 'ready';

    function submit(event: FormEvent): void {
        event.preventDefault();
        setError(null);
        upload.mutate();
    }

    return (
        <div
            data-testid="admin-team-logo-backdrop"
            style={backdropStyle}
            onMouseDown={(event) => {
                if (event.target === event.currentTarget && !busy) onClose();
            }}
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby="admin-team-logo-title"
                aria-busy={busy}
                data-testid="admin-team-logo-dialog"
                data-state={state}
                onSubmit={submit}
                style={dialogStyle}
            >
                <header style={{ display: 'flex', alignItems: 'flex-start', gap: 16 }}>
                    <div>
                        <h2 id="admin-team-logo-title" style={{ margin: 0, fontSize: 17, color: 'var(--fg-0)' }}>
                            Tenant logo
                        </h2>
                        <p style={{ margin: '5px 0 0', fontSize: 12, color: 'var(--fg-3)' }}>
                            {team.name} · shown in the desktop tenant and project selector
                        </p>
                    </div>
                    <span style={{ flex: 1 }} />
                    <button
                        type="button"
                        data-testid="admin-team-logo-close"
                        aria-label="Close tenant logo dialog"
                        disabled={busy}
                        onClick={onClose}
                        style={quietButton}
                    >
                        Close
                    </button>
                </header>

                <div data-testid="admin-team-logo-preview" style={previewStyle}>
                    {previewUrl || team.logo_url ? (
                        <img
                            src={previewUrl ?? team.logo_url ?? undefined}
                            alt={`${team.name} logo preview`}
                            style={{ maxWidth: '100%', maxHeight: 110, objectFit: 'contain' }}
                        />
                    ) : (
                        <span style={{ color: 'var(--fg-3)', fontSize: 12 }}>No logo uploaded</span>
                    )}
                </div>

                <label htmlFor="admin-team-logo-file" style={{ display: 'grid', gap: 6, color: 'var(--fg-2)', fontSize: 12 }}>
                    PNG, JPEG or WebP · max 2 MB · max 2400 × 1200 px
                    <input
                        id="admin-team-logo-file"
                        name="logo"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        data-testid="admin-team-logo-file"
                        disabled={busy}
                        required
                        onChange={(event) => {
                            setError(null);
                            setFile(event.target.files?.[0] ?? null);
                        }}
                    />
                </label>

                {error && (
                    <p role="alert" data-testid="admin-team-logo-error" style={{ margin: 0, color: 'var(--err)', fontSize: 12 }}>
                        {error}
                    </p>
                )}

                <footer style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                    {team.logo_url && (
                        <button
                            type="button"
                            data-testid="admin-team-logo-delete"
                            disabled={busy}
                            onClick={() => {
                                setError(null);
                                remove.mutate();
                            }}
                            style={{ ...quietButton, color: 'var(--err)' }}
                        >
                            {remove.isPending ? 'Removing…' : 'Remove logo'}
                        </button>
                    )}
                    <button
                        type="submit"
                        data-testid="admin-team-logo-submit"
                        disabled={busy || file === null}
                        style={primaryButton}
                    >
                        {upload.isPending ? 'Uploading…' : 'Upload logo'}
                    </button>
                </footer>
            </form>
        </div>
    );
}

const backdropStyle: React.CSSProperties = {
    position: 'fixed', inset: 0, zIndex: 1200, display: 'grid', placeItems: 'center',
    padding: 24, background: 'rgba(0,0,0,.68)',
};
const dialogStyle: React.CSSProperties = {
    width: 'min(520px, 100%)', display: 'grid', gap: 18, padding: 22,
    borderRadius: 12, border: '1px solid var(--panel-border)', background: 'var(--panel-solid)',
    boxShadow: '0 24px 70px rgba(0,0,0,.45)',
};
const previewStyle: React.CSSProperties = {
    minHeight: 140, display: 'grid', placeItems: 'center', padding: 14,
    border: '1px dashed var(--panel-border)', borderRadius: 10, background: 'var(--bg-2)',
};
const quietButton: React.CSSProperties = {
    padding: '6px 10px', borderRadius: 6, border: '1px solid var(--panel-border)',
    background: 'transparent', color: 'var(--fg-1)', cursor: 'pointer',
};
const primaryButton: React.CSSProperties = {
    ...quietButton, borderColor: 'var(--accent)', background: 'var(--accent)', color: 'white',
};
