import { useState } from 'react';
import { useAbuseSignals } from './use-invitations';
import type { AbuseAction, AbuseSeverity, AbuseSignal } from './invitations.api';
import { InvitesDataTable, type Column } from './InvitesDataTable';
import { SelectFilter } from './SelectFilter';
import { StatusBadge } from './StatusBadge';
import { deriveListState, formatDateTime, formatNumber, humanize, isCapped } from './format';
import { filterRowStyle } from './tab-styles';

const SEVERITY_OPTIONS: Array<{ value: AbuseSeverity; label: string }> = [
    { value: 'info', label: 'Info' },
    { value: 'warn', label: 'Warn' },
    { value: 'block', label: 'Block' },
];

const ACTION_OPTIONS: Array<{ value: AbuseAction; label: string }> = [
    { value: 'none', label: 'None' },
    { value: 'flag', label: 'Flag' },
    { value: 'throttle', label: 'Throttle' },
    { value: 'block', label: 'Block' },
];

export function AntiAbuseTab() {
    const [severity, setSeverity] = useState('');
    const [action, setAction] = useState('');

    const query = useAbuseSignals({
        severity: (severity || null) as AbuseSeverity | null,
        action: (action || null) as AbuseAction | null,
    });
    const rows = query.data ?? [];
    const state = deriveListState(query, rows.length);

    const columns: Column<AbuseSignal>[] = [
        // subject_value is $hidden server-side (hashed PII) — only the type shows.
        { key: 'subject', header: 'Subject', render: (s) => humanize(s.subject_type) },
        { key: 'signal', header: 'Signal', render: (s) => humanize(s.signal_type) },
        { key: 'severity', header: 'Severity', render: (s) => <StatusBadge value={s.severity} /> },
        { key: 'score', header: 'Score', align: 'right', render: (s) => formatNumber(s.score) },
        { key: 'action', header: 'Action', render: (s) => <StatusBadge value={s.action_taken} /> },
        { key: 'when', header: 'When', render: (s) => formatDateTime(s.created_at) },
    ];

    return (
        <div>
            <div style={filterRowStyle}>
                <SelectFilter
                    id="invitations-abuse-severity"
                    testid="admin-invitations-abuse-filter-severity"
                    label="Severity"
                    value={severity}
                    onChange={setSeverity}
                    options={SEVERITY_OPTIONS}
                />
                <SelectFilter
                    id="invitations-abuse-action"
                    testid="admin-invitations-abuse-filter-action"
                    label="Action"
                    value={action}
                    onChange={setAction}
                    options={ACTION_OPTIONS}
                />
            </div>
            <InvitesDataTable
                testid="admin-invitations-abuse"
                ariaLabel="Anti-abuse signals"
                state={state}
                rows={rows}
                columns={columns}
                getRowId={(s) => s.id}
                capped={isCapped(rows.length)}
                emptyLabel="No abuse signals match the current filters."
            />
        </div>
    );
}
