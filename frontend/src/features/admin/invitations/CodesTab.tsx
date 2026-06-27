import { useState } from 'react';
import { Icon } from '../../../components/Icons';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';
import { useCampaigns, useCodes, useRevokeCode } from './use-invitations';
import type { CodeState, InviteCode } from './invitations.api';
import { InvitesDataTable, type Column } from './InvitesDataTable';
import { SelectFilter } from './SelectFilter';
import { StatusBadge } from './StatusBadge';
import { CodeGeneratorDrawer } from './CodeGeneratorDrawer';
import { deriveListState, formatDateTime, formatNumber, isCapped } from './format';
import { filterRowStyle } from './tab-styles';

const STATE_OPTIONS: Array<{ value: CodeState; label: string }> = [
    { value: 'active', label: 'Active' },
    { value: 'redeemed', label: 'Redeemed' },
    { value: 'exhausted', label: 'Exhausted' },
    { value: 'expired', label: 'Expired' },
    { value: 'revoked', label: 'Revoked' },
];

export function CodesTab() {
    const toast = useToast();
    const [campaignId, setCampaignId] = useState('');
    const [codeState, setCodeState] = useState('');
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [confirming, setConfirming] = useState<Record<number, boolean>>({});

    const campaignsQuery = useCampaigns();
    const query = useCodes({
        campaign_id: campaignId ? Number(campaignId) : null,
        state: (codeState || null) as CodeState | null,
    });
    const revoke = useRevokeCode();

    const rows = query.data ?? [];
    const state = deriveListState(query, rows.length);

    async function handleRevoke(id: number) {
        setConfirming((prev) => ({ ...prev, [id]: false }));
        try {
            await revoke.mutateAsync(id);
            toast.success('Code revoked.', 'toast-code-revoked');
        } catch (err) {
            toast.error(toAdminError(err).message, 'toast-code-revoke-error');
        }
    }

    async function copyCode(code: string) {
        await navigator.clipboard.writeText(code);
        toast.success('Code copied.', 'toast-code-copied');
    }

    const columns: Column<InviteCode>[] = [
        {
            key: 'code',
            header: 'Code',
            render: (c) => (
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <code style={{ fontFamily: 'var(--font-mono, monospace)', color: 'var(--fg-0)' }}>{c.code}</code>
                    <button
                        type="button"
                        data-testid={`admin-invitations-codes-row-${c.id}-copy`}
                        aria-label={`Copy code ${c.code}`}
                        onClick={() => copyCode(c.code)}
                        style={ghostIconBtn}
                    >
                        <Icon.Copy size={12} />
                    </button>
                </span>
            ),
        },
        { key: 'kind', header: 'Kind', render: (c) => <StatusBadge value={c.code_kind} tone="muted" /> },
        { key: 'state', header: 'State', render: (c) => <StatusBadge value={c.state} /> },
        {
            key: 'uses',
            header: 'Uses',
            render: (c) => `${formatNumber(c.current_uses)} / ${formatNumber(c.max_uses)}`,
        },
        { key: 'expiry', header: 'Expiry', render: (c) => formatDateTime(c.expires_at) },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (c) => {
                if (c.state !== 'active') return <span style={{ color: 'var(--fg-3)' }}>—</span>;
                if (confirming[c.id]) {
                    return (
                        <span style={{ display: 'inline-flex', gap: 6, justifyContent: 'flex-end' }}>
                            <button
                                type="button"
                                data-testid={`admin-invitations-codes-row-${c.id}-revoke-confirm`}
                                onClick={() => handleRevoke(c.id)}
                                style={dangerBtn}
                            >
                                Confirm
                            </button>
                            <button
                                type="button"
                                data-testid={`admin-invitations-codes-row-${c.id}-revoke-cancel`}
                                onClick={() => setConfirming((prev) => ({ ...prev, [c.id]: false }))}
                                style={neutralBtn}
                            >
                                Cancel
                            </button>
                        </span>
                    );
                }
                return (
                    <button
                        type="button"
                        data-testid={`admin-invitations-codes-row-${c.id}-revoke`}
                        aria-label={`Revoke code ${c.code}`}
                        onClick={() => setConfirming((prev) => ({ ...prev, [c.id]: true }))}
                        style={neutralBtn}
                    >
                        Revoke
                    </button>
                );
            },
        },
    ];

    return (
        <div>
            <div style={{ ...filterRowStyle, justifyContent: 'space-between' }}>
                <div style={{ display: 'flex', gap: 14, alignItems: 'flex-end', flexWrap: 'wrap' }}>
                    <SelectFilter
                        id="invitations-codes-campaign"
                        testid="admin-invitations-codes-filter-campaign"
                        label="Campaign"
                        value={campaignId}
                        onChange={setCampaignId}
                        allLabel="All campaigns"
                        options={(campaignsQuery.data ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
                    />
                    <SelectFilter
                        id="invitations-codes-state"
                        testid="admin-invitations-codes-filter-state"
                        label="State"
                        value={codeState}
                        onChange={setCodeState}
                        options={STATE_OPTIONS}
                    />
                </div>
                <button
                    type="button"
                    data-testid="admin-invitations-codes-generate-open"
                    onClick={() => setDrawerOpen(true)}
                    style={primaryBtn}
                >
                    <Icon.Plus size={13} /> Generate codes
                </button>
            </div>

            <InvitesDataTable
                testid="admin-invitations-codes"
                ariaLabel="Invite codes"
                state={state}
                rows={rows}
                columns={columns}
                getRowId={(c) => c.id}
                capped={isCapped(rows.length)}
                emptyLabel="No codes yet — click Generate codes to mint a batch."
            />

            {drawerOpen && (
                <CodeGeneratorDrawer campaigns={campaignsQuery.data ?? []} onClose={() => setDrawerOpen(false)} />
            )}
        </div>
    );
}

const ghostIconBtn: React.CSSProperties = {
    display: 'inline-flex',
    padding: 3,
    border: 'none',
    background: 'transparent',
    color: 'var(--fg-3)',
    cursor: 'pointer',
};

const primaryBtn: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 6,
    padding: '6px 12px',
    borderRadius: 6,
    border: '1px solid var(--accent, #6366f1)',
    background: 'var(--accent, #6366f1)',
    color: 'white',
    fontSize: 12.5,
    cursor: 'pointer',
};

const neutralBtn: React.CSSProperties = {
    padding: '4px 10px',
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
    background: 'transparent',
    color: 'var(--fg-1)',
    fontSize: 11.5,
    cursor: 'pointer',
};

const dangerBtn: React.CSSProperties = {
    padding: '4px 10px',
    borderRadius: 6,
    border: '1px solid var(--err, #c4391d)',
    background: 'var(--err, #c4391d)',
    color: 'white',
    fontSize: 11.5,
    cursor: 'pointer',
};
