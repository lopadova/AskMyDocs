import { useState } from 'react';
import { useRewards } from './use-invitations';
import type { Reward, RewardParty, RewardState } from './invitations.api';
import { InvitesDataTable, type Column } from './InvitesDataTable';
import { SelectFilter } from './SelectFilter';
import { StatusBadge } from './StatusBadge';
import { deriveListState, formatDateTime, formatNumber, isCapped } from './format';
import { filterRowStyle } from './tab-styles';

const STATE_OPTIONS: Array<{ value: RewardState; label: string }> = [
    { value: 'pending', label: 'Pending' },
    { value: 'granted', label: 'Granted' },
    { value: 'reversed', label: 'Reversed' },
    { value: 'expired', label: 'Expired' },
];

const PARTY_OPTIONS: Array<{ value: RewardParty; label: string }> = [
    { value: 'referrer', label: 'Referrer' },
    { value: 'referee', label: 'Referee' },
];

export function RewardsTab() {
    const [rewardState, setRewardState] = useState('');
    const [party, setParty] = useState('');

    const query = useRewards({
        state: (rewardState || null) as RewardState | null,
        party: (party || null) as RewardParty | null,
    });

    const rows = query.data ?? [];
    const state = deriveListState(query, rows.length);

    const columns: Column<Reward>[] = [
        { key: 'beneficiary', header: 'Beneficiary', render: (r) => `#${r.beneficiary_id}` },
        { key: 'party', header: 'Party', render: (r) => <StatusBadge value={r.party} /> },
        { key: 'type', header: 'Type', render: (r) => r.type },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            render: (r) => `${formatNumber(r.amount)} ${r.unit}`.trim(),
        },
        { key: 'trigger', header: 'Trigger', render: (r) => r.trigger },
        { key: 'state', header: 'State', render: (r) => <StatusBadge value={r.state} /> },
        { key: 'granted', header: 'Granted', render: (r) => formatDateTime(r.granted_at) },
    ];

    return (
        <div>
            <div style={filterRowStyle}>
                <SelectFilter
                    id="invitations-rewards-state"
                    testid="admin-invitations-rewards-filter-state"
                    label="State"
                    value={rewardState}
                    onChange={setRewardState}
                    options={STATE_OPTIONS}
                />
                <SelectFilter
                    id="invitations-rewards-party"
                    testid="admin-invitations-rewards-filter-party"
                    label="Party"
                    value={party}
                    onChange={setParty}
                    options={PARTY_OPTIONS}
                />
            </div>
            <InvitesDataTable
                testid="admin-invitations-rewards"
                ariaLabel="Rewards"
                state={state}
                rows={rows}
                columns={columns}
                getRowId={(r) => r.id}
                capped={isCapped(rows.length)}
                emptyLabel="No rewards match the current filters."
            />
        </div>
    );
}
