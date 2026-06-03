import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../../lib/api';

/**
 * WidgetKey row as returned by the admin API.
 */
interface WidgetKeyRow {
    id: number;
    label: string;
    public_key: string;
    project_key: string;
    allowed_origins: string[];
    rate_limit: number;
    skill: string;
    is_active: boolean;
    last_used_at: string | null;
    sessions_count: number;
    created_at: string;
    updated_at: string;
}

interface WidgetKeysResponse {
    data: WidgetKeyRow[];
}

interface CreateKeyResponse {
    data: WidgetKeyRow;
    plain_secret: string;
    public_key: string;
}

interface RotateKeyResponse {
    data: WidgetKeyRow;
    plain_secret: string;
    public_key: string;
}

/**
 * M6.4 — Widget admin keys management view.
 *
 * Features: list keys, create, rotate (regenerate pk_ + sk_), revoke (is_active=false),
 * destroy, view allowed_origins, session count. R11 testids, R15 a11y, R14 states.
 */
export function WidgetKeysView() {
    const qc = useQueryClient();
    const [showCreate, setShowCreate] = useState(false);
    const [newLabel, setNewLabel] = useState('');
    const [newProjectKey, setNewProjectKey] = useState('');
    const [newOrigins, setNewOrigins] = useState('');
    const [rotatedCreds, setRotatedCreds] = useState<RotateKeyResponse | null>(null);
    const [createdCreds, setCreatedCreds] = useState<CreateKeyResponse | null>(null);

    const keys = useQuery({
        queryKey: ['admin-widget-keys'],
        queryFn: async () => {
            const { data } = await api.get<WidgetKeysResponse>('/api/admin/widget-keys');
            return data.data;
        },
    });

    const createKey = useMutation({
        mutationFn: async () => {
            const origins = newOrigins
                .split(',')
                .map((s: string) => s.trim())
                .filter(Boolean);
            const { data } = await api.post<CreateKeyResponse>('/api/admin/widget-keys', {
                label: newLabel.trim(),
                project_key: newProjectKey.trim(),
                allowed_origins: origins,
            });
            return data;
        },
        onSuccess: async (payload) => {
            setCreatedCreds(payload);
            setNewLabel('');
            setNewProjectKey('');
            setNewOrigins('');
            setShowCreate(false);
            await qc.invalidateQueries({ queryKey: ['admin-widget-keys'] });
        },
    });

    const rotateKey = useMutation({
        mutationFn: async (id: number) => {
            const { data } = await api.post<RotateKeyResponse>(`/api/admin/widget-keys/${id}/rotate`);
            return data;
        },
        onSuccess: async (payload) => {
            setRotatedCreds(payload);
            await qc.invalidateQueries({ queryKey: ['admin-widget-keys'] });
        },
    });

    const revokeKey = useMutation({
        mutationFn: async (id: number) => {
            await api.post(`/api/admin/widget-keys/${id}/revoke`);
        },
        onSuccess: async () => {
            await qc.invalidateQueries({ queryKey: ['admin-widget-keys'] });
        },
    });

    const destroyKey = useMutation({
        mutationFn: async (id: number) => {
            await api.delete(`/api/admin/widget-keys/${id}`);
        },
        onSuccess: async () => {
            await qc.invalidateQueries({ queryKey: ['admin-widget-keys'] });
        },
    });

    return (
        <section data-testid="admin-widget-keys-view" style={{ display: 'grid', gap: 14 }}>
            <header>
                <h1 style={{ margin: 0, fontSize: 22 }}>Widget Keys</h1>
                <p style={{ marginTop: 6, color: 'var(--fg-2)' }}>
                    Manage embeddable KITT widget credentials. Create, rotate, or revoke keys.
                </p>
            </header>

            {/* Create form */}
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                {!showCreate ? (
                    <button
                        type="button"
                        data-testid="admin-widget-keys-create-btn"
                        onClick={() => setShowCreate(true)}
                    >
                        + Create Key
                    </button>
                ) : (
                    <div data-testid="admin-widget-keys-create-form" style={{ display: 'grid', gap: 6 }}>
                        <input
                            data-testid="admin-widget-keys-label"
                            value={newLabel}
                            onChange={(e) => setNewLabel(e.target.value)}
                            placeholder="Key label (e.g. Production)"
                            aria-label="Key label"
                            style={{ minWidth: 320 }}
                        />
                        <input
                            data-testid="admin-widget-keys-project"
                            value={newProjectKey}
                            onChange={(e) => setNewProjectKey(e.target.value)}
                            placeholder="Project key"
                            aria-label="Project key"
                            style={{ minWidth: 320 }}
                        />
                        <input
                            data-testid="admin-widget-keys-origins"
                            value={newOrigins}
                            onChange={(e) => setNewOrigins(e.target.value)}
                            placeholder="Allowed origins (comma-separated)"
                            aria-label="Allowed origins"
                            style={{ minWidth: 320 }}
                        />
                        <div style={{ display: 'flex', gap: 8 }}>
                            <button
                                type="button"
                                data-testid="admin-widget-keys-create-submit"
                                disabled={newLabel.trim() === '' || newProjectKey.trim() === '' || createKey.isPending}
                                onClick={() => void createKey.mutateAsync()}
                            >
                                {createKey.isPending ? 'Creating…' : 'Create'}
                            </button>
                            <button
                                type="button"
                                onClick={() => { setShowCreate(false); setNewLabel(''); setNewProjectKey(''); setNewOrigins(''); }}
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* Created credentials – show ONCE */}
            {createdCreds && (
                <div data-testid="admin-widget-keys-created-creds" style={{ padding: 10, border: '1px solid var(--hairline)', borderRadius: 6 }}>
                    <strong>Key created — copy the secret now, it won't be shown again:</strong>
                    <pre style={{ marginTop: 8, fontSize: 12, overflowX: 'auto' }}>
                        Public key:  {createdCreds.public_key}{'\n'}
                        Secret:      {createdCreds.plain_secret}
                    </pre>
                    <button type="button" onClick={() => setCreatedCreds(null)} style={{ marginTop: 6 }}>
                        Dismiss
                    </button>
                </div>
            )}

            {/* Rotated credentials – show ONCE */}
            {rotatedCreds && (
                <div data-testid="admin-widget-keys-rotated-creds" style={{ padding: 10, border: '1px solid var(--hairline)', borderRadius: 6 }}>
                    <strong>Credentials rotated — copy the new secret now:</strong>
                    <pre style={{ marginTop: 8, fontSize: 12, overflowX: 'auto' }}>
                        Public key:  {rotatedCreds.public_key}{'\n'}
                        Secret:      {rotatedCreds.plain_secret}
                    </pre>
                    <button type="button" onClick={() => setRotatedCreds(null)} style={{ marginTop: 6 }}>
                        Dismiss
                    </button>
                </div>
            )}

            {/* Loading / error states (R14) */}
            {keys.isLoading && (
                <div data-testid="admin-widget-keys-loading" style={{ color: 'var(--fg-2)' }}>
                    Loading widget keys…
                </div>
            )}
            {keys.isError && (
                <div data-testid="admin-widget-keys-error" style={{ color: 'var(--color-danger)' }} role="alert">
                    Failed to load widget keys.
                </div>
            )}

            {/* Key list */}
            {keys.data && keys.data.length === 0 && (
                <div data-testid="admin-widget-keys-empty" style={{ color: 'var(--fg-2)' }}>
                    No widget keys yet. Create one to get started.
                </div>
            )}

            {keys.data && keys.data.length > 0 && (
                <table data-testid="admin-widget-keys-table" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                        <tr style={{ borderBottom: '1px solid var(--hairline)', textAlign: 'left' }}>
                            <th>Label</th>
                            <th>Public Key</th>
                            <th>Project</th>
                            <th>Origins</th>
                            <th>Rate Limit</th>
                            <th>Status</th>
                            <th>Sessions</th>
                            <th>Last Used</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {keys.data.map((key) => (
                            <tr key={key.id} data-testid={`admin-widget-keys-row-${key.id}`} style={{ borderBottom: '1px solid var(--hairline)' }}>
                                <td>{key.label}</td>
                                <td style={{ fontFamily: 'var(--font-mono)', fontSize: 11 }}>{key.public_key}</td>
                                <td>{key.project_key}</td>
                                <td>
                                    {key.allowed_origins.length === 0 ? (
                                        <span style={{ color: 'var(--fg-2)' }}>—</span>
                                    ) : (
                                        key.allowed_origins.join(', ')
                                    )}
                                </td>
                                <td>{key.rate_limit}/min</td>
                                <td>
                                    <span
                                        data-testid={`admin-widget-keys-status-${key.id}`}
                                        style={{
                                            padding: '2px 8px',
                                            borderRadius: 4,
                                            fontSize: 11,
                                            background: key.is_active ? 'var(--color-success-bg, #e6f9e6)' : 'var(--color-danger-bg, #fde8e8)',
                                            color: key.is_active ? 'var(--color-success, #0a7a0a)' : 'var(--color-danger, #c41e1e)',
                                        }}
                                    >
                                        {key.is_active ? 'Active' : 'Revoked'}
                                    </span>
                                </td>
                                <td>{key.sessions_count}</td>
                                <td>{key.last_used_at ? new Date(key.last_used_at).toLocaleDateString() : '—'}</td>
                                <td style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                                    {key.is_active && (
                                        <>
                                            <button
                                                type="button"
                                                data-testid={`admin-widget-keys-rotate-${key.id}`}
                                                disabled={rotateKey.isPending}
                                                onClick={() => {
                                                    if (confirm('Rotating will invalidate the current credentials. Continue?')) {
                                                        void rotateKey.mutateAsync(key.id);
                                                    }
                                                }}
                                                title="Rotate credentials (generates new pk_ + sk_)"
                                                aria-label={`Rotate key ${key.label}`}
                                            >
                                                Rotate
                                            </button>
                                            <button
                                                type="button"
                                                data-testid={`admin-widget-keys-revoke-${key.id}`}
                                                disabled={revokeKey.isPending}
                                                onClick={() => {
                                                    if (confirm('Revoke this key? It will stop accepting requests.')) {
                                                        void revokeKey.mutateAsync(key.id);
                                                    }
                                                }}
                                                title="Revoke (set inactive)"
                                                aria-label={`Revoke key ${key.label}`}
                                            >
                                                Revoke
                                            </button>
                                        </>
                                    )}
                                    <button
                                        type="button"
                                        data-testid={`admin-widget-keys-delete-${key.id}`}
                                        disabled={destroyKey.isPending}
                                        onClick={() => {
                                            if (confirm('Permanently delete this key and all its sessions?')) {
                                                void destroyKey.mutateAsync(key.id);
                                            }
                                        }}
                                        title="Hard delete (cascading)"
                                        aria-label={`Delete key ${key.label}`}
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </section>
    );
}