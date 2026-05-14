import { useState, useMemo } from 'react';
import { useMcpAudit, useMcpServers } from './mcp-tools-hooks';
import type { McpAuditEntry, McpAuditFilters, McpAuditStatus } from './mcp-tools.api';

/*
 * v5.0/W2 — Audit log browser for MCP tool calls. Backed by
 *   GET /api/admin/mcp-tool-call-audit?server_id=&tool_name=&status=&from=&to=&page=
 *
 * Each row links to its source conversation/message when available. PII-
 * redacted input is rendered as a JSON tree. Status pill colour-codes
 * ok / error / timeout / denied.
 */
export function McpToolCallAuditView() {
    const servers = useMcpServers();
    const [filters, setFilters] = useState<McpAuditFilters>({ per_page: 25, page: 1 });
    const audit = useMcpAudit(filters);

    const dataState = audit.isLoading
        ? 'loading'
        : audit.isError
            ? 'error'
            : (audit.data?.data?.length ?? 0) === 0
                ? 'empty'
                : 'ready';

    const totalPages = audit.data?.meta?.last_page ?? 1;
    const currentPage = audit.data?.meta?.current_page ?? filters.page ?? 1;

    return (
        <section
            data-testid="admin-mcp-audit"
            data-state={dataState}
            aria-busy={audit.isLoading}
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 12,
            }}
        >
            <header style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <h2 style={{ margin: 0, fontSize: 16, fontWeight: 600 }}>Tool call audit</h2>
                <span style={{ fontSize: 12.5, color: 'var(--fg-2)' }}>
                    {audit.data?.meta?.total ?? 0} entries
                </span>
            </header>

            <FilterBar
                filters={filters}
                onChange={(next) => setFilters({ ...filters, ...next, page: 1 })}
                servers={servers.data ?? []}
            />

            {dataState === 'empty' ? (
                <EmptyState />
            ) : dataState === 'error' ? (
                <ErrorState message={audit.error?.message ?? 'Audit fetch failed'} />
            ) : (
                <AuditTable rows={audit.data?.data ?? []} />
            )}

            <Pagination
                currentPage={currentPage}
                totalPages={totalPages}
                onChange={(page) => setFilters({ ...filters, page })}
            />
        </section>
    );
}

interface FilterBarProps {
    filters: McpAuditFilters;
    onChange: (next: Partial<McpAuditFilters>) => void;
    servers: { id: number; name: string }[];
}

function FilterBar({ filters, onChange, servers }: FilterBarProps) {
    return (
        <div
            style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
                gap: 10,
                padding: 12,
                borderRadius: 12,
                border: '1px solid var(--border-1)',
                background: 'var(--bg-2)',
            }}
        >
            <label style={fieldStyle}>
                <span style={fieldLabelStyle}>Server</span>
                <select
                    value={filters.server_id ?? ''}
                    onChange={(event) =>
                        onChange({
                            server_id: event.target.value ? Number(event.target.value) : undefined,
                        })
                    }
                    data-testid="admin-mcp-audit-filter-server"
                    style={inputStyle}
                >
                    <option value="">All servers</option>
                    {servers.map((server) => (
                        <option key={server.id} value={server.id}>
                            {server.name}
                        </option>
                    ))}
                </select>
            </label>
            <label style={fieldStyle}>
                <span style={fieldLabelStyle}>Tool name</span>
                <input
                    type="text"
                    value={filters.tool_name ?? ''}
                    onChange={(event) => onChange({ tool_name: event.target.value || undefined })}
                    data-testid="admin-mcp-audit-filter-tool"
                    placeholder="e.g. list_repositories"
                    style={inputStyle}
                />
            </label>
            <label style={fieldStyle}>
                <span style={fieldLabelStyle}>Status</span>
                <select
                    value={filters.status ?? ''}
                    onChange={(event) =>
                        onChange({
                            status: event.target.value ? (event.target.value as McpAuditStatus) : undefined,
                        })
                    }
                    data-testid="admin-mcp-audit-filter-status"
                    style={inputStyle}
                >
                    <option value="">Any</option>
                    <option value="ok">OK</option>
                    <option value="error">Error</option>
                    <option value="timeout">Timeout</option>
                    <option value="denied">Denied</option>
                </select>
            </label>
            <label style={fieldStyle}>
                <span style={fieldLabelStyle}>From</span>
                <input
                    type="date"
                    value={filters.from ?? ''}
                    onChange={(event) => onChange({ from: event.target.value || undefined })}
                    data-testid="admin-mcp-audit-filter-from"
                    style={inputStyle}
                />
            </label>
            <label style={fieldStyle}>
                <span style={fieldLabelStyle}>To</span>
                <input
                    type="date"
                    value={filters.to ?? ''}
                    onChange={(event) => onChange({ to: event.target.value || undefined })}
                    data-testid="admin-mcp-audit-filter-to"
                    style={inputStyle}
                />
            </label>
        </div>
    );
}

interface AuditTableProps {
    rows: McpAuditEntry[];
}

function AuditTable({ rows }: AuditTableProps) {
    return (
        <div style={{ overflowX: 'auto', border: '1px solid var(--border-1)', borderRadius: 12 }}>
            <table
                data-testid="admin-mcp-audit-table"
                style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}
            >
                <thead>
                    <tr style={{ background: 'var(--bg-2)' }}>
                        <Th>When</Th>
                        <Th>Tool</Th>
                        <Th>Server</Th>
                        <Th>User</Th>
                        <Th>Status</Th>
                        <Th>Duration</Th>
                        <Th>Input (redacted)</Th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.id}
                            data-testid={`admin-mcp-audit-row-${row.id}`}
                            style={{ borderTop: '1px solid var(--border-1)' }}
                        >
                            <Td>{new Date(row.created_at).toLocaleString()}</Td>
                            <Td>{row.tool_name}</Td>
                            <Td>{row.mcp_server_name ?? row.mcp_server_id}</Td>
                            <Td>{row.user_name ?? row.user_id ?? '—'}</Td>
                            <Td>
                                <StatusPill status={row.status} />
                            </Td>
                            <Td>{row.duration_ms} ms</Td>
                            <Td>
                                <code
                                    style={{
                                        fontFamily: 'var(--font-mono, ui-monospace)',
                                        fontSize: 11.5,
                                        color: 'var(--fg-2)',
                                        whiteSpace: 'pre-wrap',
                                        wordBreak: 'break-word',
                                        maxWidth: 280,
                                        display: 'inline-block',
                                    }}
                                >
                                    {row.input_json_redacted
                                        ? JSON.stringify(row.input_json_redacted).slice(0, 200)
                                        : '—'}
                                </code>
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function StatusPill({ status }: { status: McpAuditStatus }) {
    const palette = useMemo(() => {
        switch (status) {
            case 'ok':
                return { background: 'rgba(34,197,94,0.18)', color: '#86efac' };
            case 'error':
                return { background: 'rgba(239,68,68,0.18)', color: '#fca5a5' };
            case 'timeout':
                return { background: 'rgba(245,158,11,0.18)', color: '#fde68a' };
            case 'denied':
                return { background: 'rgba(148,163,184,0.22)', color: '#cbd5e1' };
            default:
                return { background: 'rgba(148,163,184,0.18)', color: '#cbd5e1' };
        }
    }, [status]);

    return (
        <span
            data-testid={`admin-mcp-audit-status-${status}`}
            style={{
                ...palette,
                padding: '2px 8px',
                borderRadius: 999,
                fontWeight: 600,
                fontSize: 11.5,
                textTransform: 'uppercase',
                letterSpacing: '0.04em',
            }}
        >
            {status}
        </span>
    );
}

function EmptyState() {
    return (
        <div
            data-testid="admin-mcp-audit-empty"
            style={{
                padding: 28,
                textAlign: 'center',
                color: 'var(--fg-2)',
                border: '1px dashed var(--border-2)',
                borderRadius: 12,
                background: 'var(--bg-2)',
            }}
        >
            No tool calls match the current filters.
        </div>
    );
}

function ErrorState({ message }: { message: string }) {
    return (
        <div
            data-testid="admin-mcp-audit-error"
            role="alert"
            style={{
                padding: 16,
                color: 'var(--danger-fg)',
                border: '1px solid var(--danger-border, rgba(239,68,68,0.3))',
                borderRadius: 12,
                background: 'rgba(239,68,68,0.08)',
            }}
        >
            {message}
        </div>
    );
}

interface PaginationProps {
    currentPage: number;
    totalPages: number;
    onChange: (page: number) => void;
}

function Pagination({ currentPage, totalPages, onChange }: PaginationProps) {
    if (totalPages <= 1) {
        return null;
    }
    return (
        <div
            data-testid="admin-mcp-audit-pagination"
            style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}
        >
            <button
                type="button"
                disabled={currentPage <= 1}
                onClick={() => onChange(currentPage - 1)}
                data-testid="admin-mcp-audit-prev"
                style={pageBtn(currentPage <= 1)}
            >
                ← Previous
            </button>
            <span style={{ fontSize: 12.5, color: 'var(--fg-2)', alignSelf: 'center' }}>
                Page {currentPage} / {totalPages}
            </span>
            <button
                type="button"
                disabled={currentPage >= totalPages}
                onClick={() => onChange(currentPage + 1)}
                data-testid="admin-mcp-audit-next"
                style={pageBtn(currentPage >= totalPages)}
            >
                Next →
            </button>
        </div>
    );
}

function Th({ children }: { children: React.ReactNode }) {
    return (
        <th style={{ padding: '8px 12px', textAlign: 'left', fontWeight: 600, color: 'var(--fg-2)' }}>
            {children}
        </th>
    );
}

function Td({ children }: { children: React.ReactNode }) {
    return <td style={{ padding: '8px 12px', verticalAlign: 'top' }}>{children}</td>;
}

const fieldStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4 };
const fieldLabelStyle: React.CSSProperties = { fontSize: 11.5, color: 'var(--fg-2)' };
const inputStyle: React.CSSProperties = {
    padding: '6px 8px',
    borderRadius: 8,
    border: '1px solid var(--border-2)',
    background: 'var(--bg-1)',
    color: 'var(--fg-1)',
    fontSize: 13,
};

function pageBtn(disabled: boolean): React.CSSProperties {
    return {
        padding: '4px 10px',
        borderRadius: 8,
        border: '1px solid var(--border-2)',
        background: 'var(--bg-2)',
        color: disabled ? 'var(--fg-3, #555)' : 'var(--fg-1)',
        cursor: disabled ? 'not-allowed' : 'pointer',
        fontSize: 12.5,
        fontWeight: 600,
    };
}
