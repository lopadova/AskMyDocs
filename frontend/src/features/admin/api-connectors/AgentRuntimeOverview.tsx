import { useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { apiConnectorsApi } from './api-connectors.api';

export function AgentRuntimeOverview(): ReactNode {
    const query = useQuery({
        queryKey: ['admin', 'agent-runs', 'overview'],
        queryFn: apiConnectorsApi.agentOverview,
        staleTime: 15_000,
        refetchInterval: 30_000,
    });

    if (query.isLoading) {
        return <section data-testid="agent-runtime-overview" data-state="loading" role="status" aria-busy="true" style={shellStyle}>Loading agent operations…</section>;
    }
    if (query.isError || !query.data) {
        return (
            <section data-testid="agent-runtime-overview" data-state="error" role="alert" style={shellStyle}>
                Could not load agent operations.
                <button type="button" data-testid="agent-runtime-retry" onClick={() => query.refetch()} style={retryStyle}>Retry</button>
            </section>
        );
    }

    const {
        metrics,
        planner_shadow: plannerShadow,
        mcp_transport: mcpTransport,
        policy,
        recent_runs: recent,
    } = query.data;
    return (
        <section data-testid="agent-runtime-overview" data-state="ready" aria-labelledby="agent-runtime-title" style={shellStyle}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 12 }}>
                <div>
                    <h2 id="agent-runtime-title" style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>Agent data retrieval</h2>
                    <p style={{ margin: '3px 0 0', fontSize: 11.5, color: 'var(--fg-3)' }}>Last 24 hours · documents and live APIs combined automatically</p>
                </div>
                <span data-testid="agent-runtime-policy-summary" style={{ fontSize: 10.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>
                    soft {policy.logical_soft} · hard {policy.logical_hard} logical · {policy.physical_hard} HTTP
                </span>
            </div>

            {plannerShadow.reports > 0 && (
                <details data-testid="agent-planner-shadow" style={{ marginTop: 10, border: '1px solid var(--hairline)', borderRadius: 8, background: 'var(--bg-2)' }}>
                    <summary style={{ padding: '9px 10px', cursor: 'pointer', color: 'var(--fg-1)', fontSize: 11.5 }}>
                        Capability planner shadow · {plannerShadow.agreement_rate ?? 0}% agreement · {plannerShadow.reports} comparisons
                    </summary>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: 8, padding: '0 10px 10px' }}>
                        <Metric label="Disagreements" value={plannerShadow.disagreements} detail={`${plannerShadow.errors} errors`} testId="agent-planner-disagreements" />
                        <Metric label="Invalid plans" value={plannerShadow.invalid_plan_rate == null ? '—' : `${plannerShadow.invalid_plan_rate}%`} detail={`${plannerShadow.validation_corrections} corrected`} testId="agent-planner-invalid" />
                        <Metric label="Avoided insufficient" value={plannerShadow.premature_insufficient_avoided} detail="live routes recovered" testId="agent-planner-corrections" />
                        <Metric label="Candidates" value={plannerShadow.average_candidates ?? '—'} detail="average shortlist" testId="agent-planner-candidates" />
                        <Metric label="Planner latency" value={plannerShadow.average_planner_latency_ms == null ? '—' : `${plannerShadow.average_planner_latency_ms}ms`} detail={`${plannerShadow.fallbacks} fallbacks`} testId="agent-planner-latency" />
                        <Metric label="Planner tokens" value={plannerShadow.average_tokens ?? '—'} detail="average router + planner" testId="agent-planner-tokens" />
                    </div>
                </details>
            )}

            {mcpTransport.executions > 0 && (
                <details data-testid="agent-mcp-transport" style={{ marginTop: 10, border: '1px solid var(--hairline)', borderRadius: 8, background: 'var(--bg-2)' }}>
                    <summary style={{ padding: '9px 10px', cursor: 'pointer', color: 'var(--fg-1)', fontSize: 11.5 }}>
                        MCP transport · {mcpTransport.negotiation_cache_hit_rate ?? 0}% discovery cache hit · {mcpTransport.physical_requests} requests
                    </summary>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: 8, padding: '0 10px 10px' }}>
                        <Metric label="OAuth refresh" value={duration(mcpTransport.average_oauth_refresh_ms)} detail="average" testId="agent-mcp-oauth" />
                        <Metric label="Endpoint guard" value={duration(mcpTransport.average_endpoint_guard_dns_ms)} detail="DNS + policy" testId="agent-mcp-guard" />
                        <Metric label="Discovery" value={duration(mcpTransport.average_discovery_ms)} detail="average" testId="agent-mcp-discovery" />
                        <Metric label="Tool call" value={duration(mcpTransport.average_tool_call_ms)} detail="average" testId="agent-mcp-tool-call" />
                        <Metric label="Decode" value={duration(mcpTransport.average_decode_ms)} detail="average" testId="agent-mcp-decode" />
                        <Metric label="Recoveries" value={sumCounts(mcpTransport.recoveries)} detail={`${sumCounts(mcpTransport.error_codes)} errors`} testId="agent-mcp-recoveries" />
                    </div>
                </details>
            )}

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(135px, 1fr))', gap: 8, marginTop: 12 }}>
                <Metric label="Runs" value={metrics.runs} testId="agent-runtime-runs" />
                <Metric label="Success" value={metrics.success_rate == null ? '—' : `${metrics.success_rate}%`} testId="agent-runtime-success" />
                <Metric label="Tool calls" value={metrics.logical_calls} detail={`${metrics.physical_requests} HTTP`} testId="agent-runtime-calls" />
                <Metric label="Avg. duration" value={metrics.average_duration_ms == null ? '—' : `${(metrics.average_duration_ms / 1000).toFixed(1)}s`} detail={`${metrics.tool_failures} failures`} testId="agent-runtime-duration" />
            </div>

            {recent.length > 0 && (
                <div style={{ overflowX: 'auto', marginTop: 12 }}>
                    <table data-testid="agent-runtime-recent" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 11.5 }}>
                        <thead><tr>{['Status', 'Channel', 'Project', 'Logical', 'HTTP', 'Started'].map((label) => <th key={label} style={thStyle}>{label}</th>)}</tr></thead>
                        <tbody>
                            {recent.slice(0, 6).map((run) => (
                                <tr key={run.run_id}>
                                    <td style={tdStyle}><Status status={run.status} /></td>
                                    <td style={tdStyle}>{run.channel}</td>
                                    <td style={tdStyle}>{run.project_key ?? 'All accessible'}</td>
                                    <td style={tdStyle}>{run.logical_calls}</td>
                                    <td style={tdStyle}>{run.physical_calls}</td>
                                    <td style={tdStyle}>{run.created_at ? new Date(run.created_at).toLocaleString() : '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

function duration(value: number | null): string {
    return value == null ? '—' : `${value}ms`;
}

function sumCounts(values: Record<string, number>): number {
    return Object.values(values).reduce((sum, value) => sum + value, 0);
}

function Metric({ label, value, detail, testId }: { label: string; value: string | number; detail?: string; testId: string }): ReactNode {
    return (
        <div data-testid={testId} style={{ padding: '9px 10px', borderRadius: 8, background: 'var(--bg-2)', border: '1px solid var(--hairline)' }}>
            <div style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>{label}</div>
            <div style={{ marginTop: 2, color: 'var(--fg-0)', fontSize: 17, fontWeight: 650 }}>{value}</div>
            {detail && <div style={{ color: 'var(--fg-3)', fontSize: 10 }}>{detail}</div>}
        </div>
    );
}

function Status({ status }: { status: string }): ReactNode {
    const good = status === 'completed';
    const warning = status === 'partial' || status.includes('awaiting') || status.includes('waiting');
    return <span style={{ color: good ? '#6ee7b7' : warning ? '#fbbf24' : status === 'failed' ? '#fca5a5' : 'var(--fg-2)', fontFamily: 'var(--font-mono)' }}>{status}</span>;
}

const shellStyle = { padding: 14, border: '1px solid var(--panel-border)', borderRadius: 10, background: 'var(--panel-solid)', color: 'var(--fg-2)' } as const;
const retryStyle = { marginLeft: 8, border: '1px solid var(--panel-border)', borderRadius: 6, background: 'transparent', color: 'inherit', padding: '3px 8px', cursor: 'pointer' } as const;
const thStyle = { padding: '6px 8px', color: 'var(--fg-3)', textAlign: 'left', borderBottom: '1px solid var(--hairline)', fontWeight: 500 } as const;
const tdStyle = { padding: '7px 8px', color: 'var(--fg-2)', borderBottom: '1px solid var(--hairline)' } as const;
