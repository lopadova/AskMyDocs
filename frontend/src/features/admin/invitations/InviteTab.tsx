import { useState } from 'react';
import { Icon } from '../../../components/Icons';
import type { SentInvitation } from './invitations.api';
import { InvitesDataTable, type Column } from './InvitesDataTable';
import { StatusBadge } from './StatusBadge';
import { SendInvitationDrawer } from './SendInvitationDrawer';
import { formatDateTime } from './format';
import { filterRowStyle } from './tab-styles';

/*
 * Direct invitations. The core API has no list endpoint (only POST), so this
 * tab shows the invitations sent IN THIS SESSION rather than pretending to be a
 * full history — the empty state says so explicitly (R14, no false completeness).
 */
export function InviteTab() {
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [sent, setSent] = useState<SentInvitation[]>([]);

    const state = sent.length === 0 ? 'empty' : 'ready';

    const columns: Column<SentInvitation>[] = [
        { key: 'recipient', header: 'Recipient', render: (i) => i.recipient },
        { key: 'channel', header: 'Channel', render: (i) => <StatusBadge value={i.channel} tone="muted" /> },
        { key: 'status', header: 'Status', render: (i) => <StatusBadge value={i.status} /> },
        { key: 'expires', header: 'Expires', render: (i) => formatDateTime(i.expires_at) },
    ];

    return (
        <div>
            <div style={{ ...filterRowStyle, justifyContent: 'flex-end' }}>
                <button
                    type="button"
                    data-testid="admin-invitations-invite-open"
                    onClick={() => setDrawerOpen(true)}
                    style={primaryBtn}
                >
                    <Icon.Send size={13} /> Send invitation
                </button>
            </div>

            <InvitesDataTable
                testid="admin-invitations-invites"
                ariaLabel="Invitations sent this session"
                state={state}
                rows={sent}
                columns={columns}
                getRowId={(i) => i.id}
                emptyLabel="No invitations sent in this session. The core API has no list endpoint, so only sends made here are shown."
            />

            {drawerOpen && (
                <SendInvitationDrawer
                    onClose={() => setDrawerOpen(false)}
                    onSent={(invitation) => setSent((prev) => [invitation, ...prev.filter((p) => p.id !== invitation.id)])}
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
