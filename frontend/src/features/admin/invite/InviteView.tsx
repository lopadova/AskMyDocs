import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    adminInviteApi,
    type CodeState,
    type CreateCampaignPayload,
    type GenerateCodesPayload,
    type InviteCampaign,
    type InviteCode,
    type UpdateCampaignPayload,
} from './admin-invite.api';
import { InviteCampaignForm } from './InviteCampaignForm';
import { GenerateCodesForm } from './GenerateCodesForm';
import { toAdminError } from '../shared/errors';

type Tab = 'campaigns' | 'codes' | 'metrics' | 'invitations';

const cardStyle = {
    background: 'var(--panel-solid)',
    border: '1px solid var(--panel-border)',
    borderRadius: 8,
    padding: 14,
};

const btnPrimary = {
    padding: '8px 14px',
    borderRadius: 6,
    border: 'none',
    background: 'var(--accent)',
    color: '#fff',
    cursor: 'pointer',
};

const btnGhost = {
    padding: '6px 10px',
    borderRadius: 6,
    border: '1px solid var(--panel-border)',
    background: 'transparent',
    color: 'var(--fg-1)',
    cursor: 'pointer',
    fontSize: 12,
};

/**
 * Admin invite management — campaigns, codes, metrics, invitations
 * (R11 testids, R14/R15 states + a11y, R44 same endpoints as PHP/MCP).
 */
export function InviteView(): ReactNode {
    const [tab, setTab] = useState<Tab>('campaigns');

    return (
        <div data-testid="admin-invite-view" style={{ padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
            <header style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Invites</h1>
                <span style={{ flex: 1 }} />
            </header>

            <nav role="tablist" aria-label="Invite sections" style={{ display: 'flex', gap: 4, borderBottom: '1px solid var(--panel-border)' }}>
                {(['campaigns', 'codes', 'metrics', 'invitations'] as Tab[]).map((t) => (
                    <button
                        key={t}
                        role="tab"
                        aria-selected={tab === t}
                        data-testid={`admin-invite-tab-${t}`}
                        onClick={() => setTab(t)}
                        style={{
                            padding: '8px 14px',
                            border: 'none',
                            background: 'transparent',
                            color: tab === t ? 'var(--fg-0)' : 'var(--fg-2)',
                            borderBottom: tab === t ? '2px solid var(--accent)' : '2px solid transparent',
                            cursor: 'pointer',
                            fontSize: 13,
                            textTransform: 'capitalize',
                        }}
                    >
                        {t}
                    </button>
                ))}
            </nav>

            {tab === 'campaigns' && <CampaignsPanel />}
            {tab === 'codes' && <CodesPanel />}
            {tab === 'metrics' && <MetricsPanel />}
            {tab === 'invitations' && <InvitationsPanel />}
        </div>
    );
}

// ──────────────────────────────────────────────────────────────
// Campaigns
// ──────────────────────────────────────────────────────────────
function CampaignsPanel(): ReactNode {
    const qc = useQueryClient();
    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<InviteCampaign | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);

    const query = useQuery({ queryKey: ['admin-invite-campaigns'], queryFn: () => adminInviteApi.listCampaigns(), staleTime: 30_000 });

    const createMutation = useMutation({
        mutationFn: (payload: CreateCampaignPayload) => adminInviteApi.createCampaign(payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-invite-campaigns'] });
            setCreateOpen(false);
            setSubmitError(null);
        },
        onError: (err) => setSubmitError(toAdminError(err).message),
    });

    const updateMutation = useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: UpdateCampaignPayload }) => adminInviteApi.updateCampaign(id, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-invite-campaigns'] });
            setEditing(null);
            setSubmitError(null);
        },
        onError: (err) => setSubmitError(toAdminError(err).message),
    });

    const campaigns = query.data ?? [];

    return (
        <section data-testid="admin-invite-campaigns" aria-label="Campaigns">
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 12 }}>
                <span data-testid="admin-invite-campaigns-count" style={{ fontSize: 12, color: 'var(--fg-2)' }}>{campaigns.length} total</span>
                <span style={{ flex: 1 }} />
                <button type="button" data-testid="admin-invite-create" onClick={() => { setSubmitError(null); setCreateOpen(true); }} style={btnPrimary}>
                    + New campaign
                </button>
            </div>

            {query.isLoading && <p data-testid="admin-invite-loading" data-state="loading" style={{ color: 'var(--fg-2)' }}>Loading…</p>}
            {query.isError && <p data-testid="admin-invite-error" data-state="error" role="alert" style={{ color: 'var(--err)' }}>Could not load campaigns.</p>}
            {!query.isLoading && !query.isError && campaigns.length === 0 && (
                <p data-testid="admin-invite-empty" data-state="empty" style={{ color: 'var(--fg-2)' }}>No campaigns yet. Click <code>+ New campaign</code> to create one.</p>
            )}

            {campaigns.length > 0 && (
                <table data-testid="admin-invite-table" data-state="ready" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                        <tr style={{ textAlign: 'left', color: 'var(--fg-3)', fontSize: 11 }}>
                            <th style={{ padding: 6 }}>Key</th>
                            <th style={{ padding: 6 }}>Name</th>
                            <th style={{ padding: 6 }}>Type</th>
                            <th style={{ padding: 6 }}>Status</th>
                            <th style={{ padding: 6 }}>Per-user</th>
                            <th style={{ padding: 6, textAlign: 'right' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {campaigns.map((c) => (
                            <tr key={c.id} data-testid={`admin-invite-campaign-row-${c.id}`} data-campaign-key={c.key} style={{ borderTop: '1px solid var(--panel-border)', color: 'var(--fg-1)' }}>
                                <td style={{ padding: 6, fontFamily: 'var(--font-mono)' }}>{c.key}</td>
                                <td style={{ padding: 6 }}>{c.name}</td>
                                <td style={{ padding: 6 }}>{c.type}</td>
                                <td style={{ padding: 6 }}><span data-testid={`admin-invite-campaign-row-${c.id}-status`}>{c.status}</span></td>
                                <td style={{ padding: 6 }}>{c.per_user_limit}</td>
                                <td style={{ padding: 6, textAlign: 'right' }}>
                                    <button type="button" data-testid={`admin-invite-campaign-row-${c.id}-edit`} aria-label={`Edit campaign ${c.name}`} onClick={() => { setSubmitError(null); setEditing(c); }} style={btnGhost}>
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {createOpen && (
                <InviteCampaignForm campaign={null} onClose={() => setCreateOpen(false)} onSubmit={(p) => createMutation.mutate(p as CreateCampaignPayload)} submitError={submitError} isSubmitting={createMutation.isPending} />
            )}
            {editing !== null && (
                <InviteCampaignForm campaign={editing} onClose={() => setEditing(null)} onSubmit={(p) => updateMutation.mutate({ id: editing.id, payload: p as UpdateCampaignPayload })} submitError={submitError} isSubmitting={updateMutation.isPending} />
            )}
        </section>
    );
}

// ──────────────────────────────────────────────────────────────
// Codes
// ──────────────────────────────────────────────────────────────
function CodesPanel(): ReactNode {
    const qc = useQueryClient();
    const [campaignFilter, setCampaignFilter] = useState<string>('');
    const [stateFilter, setStateFilter] = useState<CodeState | ''>('');
    const [genOpen, setGenOpen] = useState(false);
    const [genError, setGenError] = useState<string | null>(null);
    const [generated, setGenerated] = useState<InviteCode[] | null>(null);
    const [confirmingRevoke, setConfirmingRevoke] = useState<Record<number, boolean>>({});

    const campaignsQuery = useQuery({ queryKey: ['admin-invite-campaigns'], queryFn: () => adminInviteApi.listCampaigns(), staleTime: 30_000 });
    const codesQuery = useQuery({
        queryKey: ['admin-invite-codes', campaignFilter, stateFilter],
        queryFn: () => adminInviteApi.listCodes({ campaign_id: campaignFilter === '' ? null : Number.parseInt(campaignFilter, 10), state: stateFilter }),
        staleTime: 15_000,
    });

    const generateMutation = useMutation({
        mutationFn: (payload: GenerateCodesPayload) => adminInviteApi.generateCodes(payload),
        onSuccess: (codes) => {
            setGenerated(codes);
            setGenError(null);
            qc.invalidateQueries({ queryKey: ['admin-invite-codes'] });
        },
        onError: (err) => setGenError(toAdminError(err).message),
    });

    const revokeMutation = useMutation({
        mutationFn: (id: number) => adminInviteApi.revokeCode(id),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-invite-codes'] }),
    });

    const codes = codesQuery.data ?? [];
    const campaigns = campaignsQuery.data ?? [];

    return (
        <section data-testid="admin-invite-codes" aria-label="Codes">
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 12, flexWrap: 'wrap' }}>
                <label style={{ fontSize: 12, color: 'var(--fg-2)' }}>
                    Campaign{' '}
                    <select data-testid="admin-invite-codes-campaign-filter" value={campaignFilter} onChange={(e) => setCampaignFilter(e.target.value)} style={{ padding: 6, borderRadius: 6, border: '1px solid var(--panel-border)', background: 'var(--bg-3)', color: 'var(--fg-1)' }}>
                        <option value="">all</option>
                        {campaigns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </label>
                <label style={{ fontSize: 12, color: 'var(--fg-2)' }}>
                    State{' '}
                    <select data-testid="admin-invite-codes-state-filter" value={stateFilter} onChange={(e) => setStateFilter(e.target.value as CodeState | '')} style={{ padding: 6, borderRadius: 6, border: '1px solid var(--panel-border)', background: 'var(--bg-3)', color: 'var(--fg-1)' }}>
                        <option value="">all</option>
                        {(['active', 'redeemed', 'exhausted', 'expired', 'revoked'] as CodeState[]).map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                </label>
                <span data-testid="admin-invite-codes-count" style={{ fontSize: 12, color: 'var(--fg-2)' }}>{codes.length} shown</span>
                <span style={{ flex: 1 }} />
                <button type="button" data-testid="admin-invite-codes-generate" onClick={() => { setGenerated(null); setGenError(null); setGenOpen(true); }} style={btnPrimary}>
                    Generate codes
                </button>
            </div>

            {codesQuery.isLoading && <p data-testid="admin-invite-codes-loading" data-state="loading" style={{ color: 'var(--fg-2)' }}>Loading…</p>}
            {codesQuery.isError && <p data-testid="admin-invite-codes-error" data-state="error" role="alert" style={{ color: 'var(--err)' }}>Could not load codes.</p>}
            {!codesQuery.isLoading && !codesQuery.isError && codes.length === 0 && (
                <p data-testid="admin-invite-codes-empty" data-state="empty" style={{ color: 'var(--fg-2)' }}>No codes match. Generate a batch to get started.</p>
            )}

            {codes.length > 0 && (
                <table data-testid="admin-invite-codes-table" data-state="ready" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                        <tr style={{ textAlign: 'left', color: 'var(--fg-3)', fontSize: 11 }}>
                            <th style={{ padding: 6 }}>Code</th>
                            <th style={{ padding: 6 }}>Kind</th>
                            <th style={{ padding: 6 }}>State</th>
                            <th style={{ padding: 6 }}>Uses</th>
                            <th style={{ padding: 6 }}>Expires</th>
                            <th style={{ padding: 6, textAlign: 'right' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {codes.map((c) => {
                            const isConfirming = confirmingRevoke[c.id] === true;
                            const revocable = c.state === 'active' || c.state === 'redeemed';
                            return (
                                <tr key={c.id} data-testid={`admin-invite-code-row-${c.id}`} data-code-state={c.state} style={{ borderTop: '1px solid var(--panel-border)', color: 'var(--fg-1)' }}>
                                    <td style={{ padding: 6, fontFamily: 'var(--font-mono)' }}>{c.code}</td>
                                    <td style={{ padding: 6 }}>{c.code_kind}</td>
                                    <td style={{ padding: 6 }}><span data-testid={`admin-invite-code-row-${c.id}-state`}>{c.state}</span></td>
                                    <td style={{ padding: 6 }}>{c.current_uses}/{c.max_uses}</td>
                                    <td style={{ padding: 6 }}>{c.expires_at ? new Date(c.expires_at).toLocaleDateString() : '—'}</td>
                                    <td style={{ padding: 6, textAlign: 'right' }}>
                                        {revocable && !isConfirming && (
                                            <button type="button" data-testid={`admin-invite-code-row-${c.id}-revoke`} aria-label={`Revoke code ${c.code}`} onClick={() => setConfirmingRevoke((p) => ({ ...p, [c.id]: true }))} style={btnGhost}>
                                                Revoke
                                            </button>
                                        )}
                                        {revocable && isConfirming && (
                                            <>
                                                <button type="button" data-testid={`admin-invite-code-row-${c.id}-revoke-confirm`} onClick={() => { revokeMutation.mutate(c.id); setConfirmingRevoke((p) => ({ ...p, [c.id]: false })); }} style={{ ...btnGhost, color: 'var(--err)', borderColor: 'var(--err)' }}>
                                                    Confirm
                                                </button>
                                                <button type="button" data-testid={`admin-invite-code-row-${c.id}-revoke-cancel`} onClick={() => setConfirmingRevoke((p) => ({ ...p, [c.id]: false }))} style={btnGhost}>
                                                    Cancel
                                                </button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            )}

            {genOpen && (
                <GenerateCodesForm
                    campaigns={campaigns}
                    defaultCampaignId={campaignFilter === '' ? null : Number.parseInt(campaignFilter, 10)}
                    onClose={() => setGenOpen(false)}
                    onSubmit={(p) => generateMutation.mutate(p)}
                    submitError={genError}
                    isSubmitting={generateMutation.isPending}
                    generated={generated}
                />
            )}
        </section>
    );
}

// ──────────────────────────────────────────────────────────────
// Metrics
// ──────────────────────────────────────────────────────────────
function MetricsPanel(): ReactNode {
    const [campaignFilter, setCampaignFilter] = useState<string>('');
    const campaignsQuery = useQuery({ queryKey: ['admin-invite-campaigns'], queryFn: () => adminInviteApi.listCampaigns(), staleTime: 30_000 });
    const metricsQuery = useQuery({
        queryKey: ['admin-invite-metrics', campaignFilter],
        queryFn: () => adminInviteApi.metrics({ campaign_id: campaignFilter === '' ? null : Number.parseInt(campaignFilter, 10) }),
        staleTime: 15_000,
    });

    const m = metricsQuery.data;
    const campaigns = campaignsQuery.data ?? [];

    const cards: Array<{ key: string; label: string; value: string }> = m
        ? [
            { key: 'codes_issued', label: 'Codes issued', value: String(m.codes_issued) },
            { key: 'redemptions', label: 'Redemptions', value: String(m.redemptions) },
            { key: 'conversion_rate', label: 'Conversion', value: `${(m.conversion_rate * 100).toFixed(1)}%` },
            { key: 'k_factor', label: 'K-factor', value: m.k_factor.toFixed(2) },
            { key: 'acceptance_rate', label: 'Acceptance', value: `${(m.acceptance_rate * 100).toFixed(1)}%` },
            { key: 'invites_sent', label: 'Invites sent', value: String(m.invites_sent) },
            { key: 'invites_accepted', label: 'Invites accepted', value: String(m.invites_accepted) },
            { key: 'distinct_referrers', label: 'Referrers', value: String(m.distinct_referrers) },
        ]
        : [];

    return (
        <section data-testid="admin-invite-metrics" aria-label="Metrics">
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 12 }}>
                <label style={{ fontSize: 12, color: 'var(--fg-2)' }}>
                    Campaign{' '}
                    <select data-testid="admin-invite-metrics-campaign-filter" value={campaignFilter} onChange={(e) => setCampaignFilter(e.target.value)} style={{ padding: 6, borderRadius: 6, border: '1px solid var(--panel-border)', background: 'var(--bg-3)', color: 'var(--fg-1)' }}>
                        <option value="">all campaigns</option>
                        {campaigns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </label>
            </div>

            {metricsQuery.isLoading && <p data-testid="admin-invite-metrics-loading" data-state="loading" style={{ color: 'var(--fg-2)' }}>Loading…</p>}
            {metricsQuery.isError && <p data-testid="admin-invite-metrics-error" data-state="error" role="alert" style={{ color: 'var(--err)' }}>Could not load metrics.</p>}

            {m && (
                <div data-testid="admin-invite-metrics-grid" data-state="ready" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: 12 }}>
                    {cards.map((card) => (
                        <div key={card.key} data-testid={`admin-invite-metric-${card.key}`} style={cardStyle}>
                            <div style={{ fontSize: 11, color: 'var(--fg-3)' }}>{card.label}</div>
                            <div style={{ fontSize: 22, color: 'var(--fg-0)', fontWeight: 600 }}>{card.value}</div>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

// ──────────────────────────────────────────────────────────────
// Invitations
// ──────────────────────────────────────────────────────────────
function InvitationsPanel(): ReactNode {
    const [recipient, setRecipient] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [sent, setSent] = useState<string | null>(null);

    const sendMutation = useMutation({
        mutationFn: (email: string) => adminInviteApi.sendInvitation({ recipient: email }),
        onSuccess: (inv) => { setSent(inv.recipient); setError(null); setRecipient(''); },
        onError: (err) => { setError(toAdminError(err).message); setSent(null); },
    });

    return (
        <section data-testid="admin-invite-invitations" aria-label="Invitations">
            <form
                data-testid="admin-invite-invitation-form"
                onSubmit={(e) => { e.preventDefault(); if (recipient.trim() !== '') sendMutation.mutate(recipient.trim()); }}
                style={{ ...cardStyle, maxWidth: 480, display: 'flex', flexDirection: 'column', gap: 10 }}
            >
                <h2 style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>Send an invitation</h2>
                <label htmlFor="admin-invite-invitation-recipient" style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--fg-2)' }}>
                    <span>Recipient email</span>
                    <input id="admin-invite-invitation-recipient" data-testid="admin-invite-invitation-recipient" type="email" required value={recipient} onChange={(e) => setRecipient(e.target.value)} placeholder="friend@example.com" style={{ padding: '8px 10px', borderRadius: 6, border: '1px solid var(--panel-border)', background: 'var(--bg-3)', color: 'var(--fg-1)' }} />
                </label>
                {error && <p data-testid="admin-invite-invitation-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, margin: 0 }}>{error}</p>}
                {sent && <p data-testid="admin-invite-invitation-sent" role="status" style={{ color: 'var(--fg-2)', fontSize: 12, margin: 0 }}>Invitation queued to {sent}.</p>}
                <div>
                    <button type="submit" data-testid="admin-invite-invitation-submit" disabled={sendMutation.isPending} style={{ ...btnPrimary, opacity: sendMutation.isPending ? 0.6 : 1 }}>
                        {sendMutation.isPending ? 'Sending…' : 'Send invitation'}
                    </button>
                </div>
            </form>
        </section>
    );
}
