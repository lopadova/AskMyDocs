import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AdminShell } from '../shell/AdminShell';
import { complianceReportsApi, type ComplianceReportListItem } from './compliance-reports.api';

function quarterBounds(year: number, quarter: 1 | 2 | 3 | 4): { start: string; end: string } {
    const startMonth = (quarter - 1) * 3;
    const start = new Date(Date.UTC(year, startMonth, 1));
    const end = new Date(Date.UTC(year, startMonth + 3, 0));

    const toIsoDate = (d: Date) => d.toISOString().slice(0, 10);

    return { start: toIsoDate(start), end: toIsoDate(end) };
}

export function ComplianceReportsView() {
    const now = new Date();
    const [tenantId, setTenantId] = useState('tenant-acme');
    const [year, setYear] = useState<number>(now.getUTCFullYear());
    const [quarter, setQuarter] = useState<1 | 2 | 3 | 4>((Math.floor(now.getUTCMonth() / 3) + 1) as 1 | 2 | 3 | 4);
    const [verifyState, setVerifyState] = useState<Record<number, 'idle' | 'ok' | 'fail'>>({});

    const qc = useQueryClient();
    const reportQuery = useQuery({
        queryKey: ['admin-compliance-reports', tenantId],
        queryFn: () => complianceReportsApi.list(tenantId),
    });

    const generateMutation = useMutation({
        mutationFn: async () => {
            const bounds = quarterBounds(year, quarter);

            await complianceReportsApi.generate({
                tenant_id: tenantId,
                period_start: bounds.start,
                period_end: bounds.end,
            });
        },
        onSuccess: async () => {
            await qc.invalidateQueries({ queryKey: ['admin-compliance-reports'] });
        },
    });

    const sortedRows = useMemo(
        () => [...(reportQuery.data ?? [])].sort((a, b) => (a.generated_at ?? '') < (b.generated_at ?? '') ? 1 : -1),
        [reportQuery.data],
    );

    async function runVerify(row: ComplianceReportListItem) {
        const response = await complianceReportsApi.verify(row.id);
        setVerifyState((prev) => ({ ...prev, [row.id]: response.valid ? 'ok' : 'fail' }));
    }

    return (
        <AdminShell section="compliance-reports">
            <div data-testid="compliance-reports-root" style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                <h1 style={{ margin: 0, fontSize: 22 }}>Compliance Reports</h1>
                <p style={{ margin: 0, color: 'var(--fg-3)', fontSize: 13 }}>
                    Generate quarterly snapshots, verify tamper-evident hashes, and export PDF/JSON evidence.
                </p>

                <div
                    style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}
                    data-testid="compliance-reports-generate-bar"
                >
                    <input
                        data-testid="compliance-reports-tenant"
                        value={tenantId}
                        onChange={(e) => setTenantId(e.target.value)}
                        placeholder="tenant-id"
                    />
                    <select
                        data-testid="compliance-reports-quarter"
                        value={quarter}
                        onChange={(e) => setQuarter(Number(e.target.value) as 1 | 2 | 3 | 4)}
                    >
                        <option value={1}>Q1</option>
                        <option value={2}>Q2</option>
                        <option value={3}>Q3</option>
                        <option value={4}>Q4</option>
                    </select>
                    <input
                        data-testid="compliance-reports-year"
                        type="number"
                        value={year}
                        onChange={(e) => setYear(Number(e.target.value))}
                        min={2020}
                        max={2100}
                    />
                    <button
                        type="button"
                        data-testid="compliance-reports-generate"
                        onClick={() => void generateMutation.mutateAsync()}
                        disabled={generateMutation.isPending || tenantId.trim() === ''}
                    >
                        {generateMutation.isPending ? 'Generating…' : `Generate Q${quarter} ${year}`}
                    </button>
                </div>

                <div data-testid="compliance-reports-table" style={{ border: '1px solid var(--hairline)', borderRadius: 10 }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                        <thead>
                            <tr>
                                <th align="left">Tenant</th>
                                <th align="left">Period</th>
                                <th align="left">Generated</th>
                                <th align="left">Hash Verify</th>
                                <th align="left">Exports</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sortedRows.map((row) => (
                                <tr key={row.id} data-testid={`compliance-reports-row-${row.id}`}>
                                    <td>{row.tenant_id}</td>
                                    <td>{row.period_start} → {row.period_end}</td>
                                    <td>{row.generated_at ?? '-'}</td>
                                    <td>
                                        <button
                                            type="button"
                                            data-testid={`compliance-reports-verify-${row.id}`}
                                            onClick={() => void runVerify(row)}
                                        >
                                            Verify
                                        </button>
                                        <span data-testid={`compliance-reports-verify-badge-${row.id}`} style={{ marginLeft: 8 }}>
                                            {verifyState[row.id] === 'ok' ? 'valid' : verifyState[row.id] === 'fail' ? 'invalid' : 'not checked'}
                                        </span>
                                    </td>
                                    <td>
                                        <a
                                            href={`/api/admin/compliance/reports/${row.id}/json`}
                                            data-testid={`compliance-reports-download-json-${row.id}`}
                                        >
                                            JSON
                                        </a>
                                        {' · '}
                                        <a
                                            href={`/api/admin/compliance/reports/${row.id}/pdf`}
                                            data-testid={`compliance-reports-download-pdf-${row.id}`}
                                        >
                                            PDF
                                        </a>
                                    </td>
                                </tr>
                            ))}
                            {sortedRows.length === 0 && (
                                <tr>
                                    <td colSpan={5} style={{ color: 'var(--fg-3)' }}>No reports yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                    {reportQuery.isLoading && <p style={{ margin: 12 }}>Loading…</p>}
                    {reportQuery.isError && <p style={{ margin: 12, color: 'var(--danger)' }}>Failed to load reports.</p>}
                </div>
            </div>
        </AdminShell>
    );
}

