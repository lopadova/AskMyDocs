import { useState } from 'react';
import { Icon } from '../../../components/Icons';
import { useCampaigns, useTenants } from './use-invitations';
import type { Campaign } from './invitations.api';
import { InvitesDataTable, type Column } from './InvitesDataTable';
import { StatusBadge } from './StatusBadge';
import { CampaignDrawer } from './CampaignDrawer';
import { deriveListState, formatDateTime, formatNumber, isCapped } from './format';
import { filterRowStyle } from './tab-styles';

export function CampaignsTab() {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Campaign | null>(null);

    const query = useCampaigns();
    const tenantsQuery = useTenants();
    const rows = query.data ?? [];
    const state = deriveListState(query, rows.length);

    const columns: Column<Campaign>[] = [
        { key: 'key', header: 'Key', render: (c) => <code style={{ fontFamily: 'var(--font-mono, monospace)', color: 'var(--fg-1)' }}>{c.key}</code> },
        { key: 'name', header: 'Name', render: (c) => c.name },
        { key: 'type', header: 'Type', render: (c) => <StatusBadge value={c.type} tone="muted" /> },
        { key: 'status', header: 'Status', render: (c) => <StatusBadge value={c.status} /> },
        { key: 'max', header: 'Max', align: 'right', render: (c) => (c.max_redemptions_total == null ? '∞' : formatNumber(c.max_redemptions_total)) },
        {
            key: 'window',
            header: 'Window',
            render: (c) => (c.starts_at || c.ends_at ? `${formatDateTime(c.starts_at)} → ${formatDateTime(c.ends_at)}` : '—'),
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (c) => (
                <button
                    type="button"
                    data-testid={`admin-invitations-campaigns-row-${c.id}-edit`}
                    aria-label={`Edit campaign ${c.name}`}
                    onClick={() => setEditing(c)}
                    style={editBtn}
                >
                    Edit
                </button>
            ),
        },
    ];

    return (
        <div>
            <div style={{ ...filterRowStyle, justifyContent: 'flex-end' }}>
                <button
                    type="button"
                    data-testid="admin-invitations-campaigns-new"
                    onClick={() => setCreating(true)}
                    style={primaryBtn}
                >
                    <Icon.Plus size={13} /> New campaign
                </button>
            </div>

            <InvitesDataTable
                testid="admin-invitations-campaigns"
                ariaLabel="Campaigns"
                state={state}
                rows={rows}
                columns={columns}
                getRowId={(c) => c.id}
                capped={isCapped(rows.length)}
                emptyLabel="No campaigns yet — click New campaign to create one."
            />

            {(creating || editing !== null) && (
                <CampaignDrawer
                    campaign={editing}
                    tenants={tenantsQuery.data ?? []}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            )}
        </div>
    );
}

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

const editBtn: React.CSSProperties = {
    padding: '4px 10px',
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
    background: 'transparent',
    color: 'var(--fg-1)',
    fontSize: 11.5,
    cursor: 'pointer',
};
