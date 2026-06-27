import { useState } from 'react';
import { useWaitlist } from './use-invitations';
import type { WaitlistEntry, WaitlistStatus } from './invitations.api';
import { InvitesDataTable, type Column } from './InvitesDataTable';
import { SelectFilter } from './SelectFilter';
import { StatusBadge } from './StatusBadge';
import { deriveListState, formatDateTime, formatNumber, isCapped, maskEmail } from './format';
import { filterRowStyle } from './tab-styles';

const STATUS_OPTIONS: Array<{ value: WaitlistStatus; label: string }> = [
    { value: 'waiting', label: 'Waiting' },
    { value: 'invited', label: 'Invited' },
    { value: 'converted', label: 'Converted' },
    { value: 'removed', label: 'Removed' },
];

export function WaitlistTab() {
    const [status, setStatus] = useState('');

    const query = useWaitlist({ status: (status || null) as WaitlistStatus | null });
    const rows = query.data ?? [];
    const state = deriveListState(query, rows.length);

    const columns: Column<WaitlistEntry>[] = [
        { key: 'position', header: '#', align: 'right', render: (w) => formatNumber(w.position) },
        // Raw PII column → masked in the admin table (waitlist email is direct PII).
        { key: 'email', header: 'Email', render: (w) => maskEmail(w.email) },
        { key: 'priority', header: 'Priority', align: 'right', render: (w) => formatNumber(w.priority) },
        { key: 'referrals', header: 'Referrals', align: 'right', render: (w) => formatNumber(w.referral_count) },
        { key: 'status', header: 'Status', render: (w) => <StatusBadge value={w.status} /> },
        { key: 'invited', header: 'Invited', render: (w) => formatDateTime(w.invited_at) },
    ];

    return (
        <div>
            <div style={filterRowStyle}>
                <SelectFilter
                    id="invitations-waitlist-status"
                    testid="admin-invitations-waitlist-filter-status"
                    label="Status"
                    value={status}
                    onChange={setStatus}
                    options={STATUS_OPTIONS}
                />
            </div>
            <InvitesDataTable
                testid="admin-invitations-waitlist"
                ariaLabel="Waitlist"
                state={state}
                rows={rows}
                columns={columns}
                getRowId={(w) => w.id}
                capped={isCapped(rows.length)}
                emptyLabel="No waitlist entries match the current filters."
            />
        </div>
    );
}
