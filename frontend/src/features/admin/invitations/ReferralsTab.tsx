import { useState } from 'react';
import { useCampaigns, useReferrals } from './use-invitations';
import type { ReferralStatus } from './invitations.api';
import { InvitesDataTable, type Column } from './InvitesDataTable';
import { SelectFilter } from './SelectFilter';
import { StatusBadge } from './StatusBadge';
import { deriveListState, formatDateTime, isCapped } from './format';
import { filterRowStyle } from './tab-styles';
import type { Referral } from './invitations.api';

const STATUS_OPTIONS: Array<{ value: ReferralStatus; label: string }> = [
    { value: 'pending', label: 'Pending' },
    { value: 'qualified', label: 'Qualified' },
    { value: 'rewarded', label: 'Rewarded' },
    { value: 'reversed', label: 'Reversed' },
];

export function ReferralsTab() {
    const [campaignId, setCampaignId] = useState('');
    const [status, setStatus] = useState('');

    const campaignsQuery = useCampaigns();
    const query = useReferrals({
        campaign_id: campaignId ? Number(campaignId) : null,
        status: (status || null) as ReferralStatus | null,
    });

    const rows = query.data ?? [];
    const state = deriveListState(query, rows.length);

    const columns: Column<Referral>[] = [
        { key: 'pair', header: 'Referrer → Referee', render: (r) => `#${r.referrer_id} → #${r.referee_id}` },
        { key: 'code', header: 'Code', render: (r) => `#${r.code_id}` },
        { key: 'status', header: 'Status', render: (r) => <StatusBadge value={r.status} /> },
        { key: 'attributed', header: 'Attributed', render: (r) => formatDateTime(r.attributed_at) },
        { key: 'qualified', header: 'Qualified', render: (r) => formatDateTime(r.qualified_at) },
    ];

    return (
        <div>
            <div style={filterRowStyle}>
                <SelectFilter
                    id="invitations-referrals-campaign"
                    testid="admin-invitations-referrals-filter-campaign"
                    label="Campaign"
                    value={campaignId}
                    onChange={setCampaignId}
                    allLabel="All campaigns"
                    options={(campaignsQuery.data ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
                />
                <SelectFilter
                    id="invitations-referrals-status"
                    testid="admin-invitations-referrals-filter-status"
                    label="Status"
                    value={status}
                    onChange={setStatus}
                    options={STATUS_OPTIONS}
                />
            </div>
            <InvitesDataTable
                testid="admin-invitations-referrals"
                ariaLabel="Referrals"
                state={state}
                rows={rows}
                columns={columns}
                getRowId={(r) => r.id}
                capped={isCapped(rows.length)}
                emptyLabel="No referrals match the current filters."
            />
        </div>
    );
}
