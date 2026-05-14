import { useState } from 'react';
import { AdminShell } from '../shell/AdminShell';
import {
    useDeleteMcpServer,
    useDisableMcpServer,
    useHandshakeMcpServer,
    useMcpServers,
    useUpdateMcpServerTools,
} from './mcp-tools-hooks';
import { HandshakeStatus } from './HandshakeStatus';
import { ToolMatrix } from './ToolMatrix';
import { RegisterServerDialog } from './RegisterServerDialog';
import { McpToolCallAuditView } from './McpToolCallAuditView';
import type { McpServerEntry } from './mcp-tools.api';

/*
 * v5.0/W2 — MCP tools admin landing. Two tabs:
 *   1. "Servers" — registered MCP servers, handshake CTA, tool matrix
 *   2. "Audit log" — paginated tool call audit with filters
 *
 * The page lives at /app/admin/mcp-tools and is reachable from the
 * AdminShell rail when `manageMcpTools` is granted.
 */
export function McpToolsView() {
    const [tab, setTab] = useState<'servers' | 'audit'>('servers');
    const [showRegister, setShowRegister] = useState(false);
    const [highlightId, setHighlightId] = useState<number | null>(null);

    return (
        <AdminShell section="mcp-tools">
            <section
                data-testid="admin-mcp-tools"
                data-state="ready"
                aria-labelledby="admin-mcp-tools-title"
                style={{
                    flex: 1,
                    padding: '24px 24px 48px',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 18,
                    minWidth: 0,
                }}
            >
                <header style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                    <h1
                        id="admin-mcp-tools-title"
                        data-testid="admin-mcp-tools-title"
                        style={{ margin: 0, fontSize: 22, fontWeight: 700, letterSpacing: '-0.01em' }}
                    >
                        MCP tools
                    </h1>
                    <nav
                        role="tablist"
                        aria-label="MCP tools sections"
                        style={{ display: 'flex', gap: 8 }}
                    >
                        <TabButton id="servers" current={tab} onSelect={setTab}>
                            Servers
                        </TabButton>
                        <TabButton id="audit" current={tab} onSelect={setTab}>
                            Audit log
                        </TabButton>
                    </nav>
                    <div style={{ flex: 1 }} />
                    {tab === 'servers' ? (
                        <button
                            type="button"
                            data-testid="admin-mcp-tools-register"
                            onClick={() => setShowRegister(true)}
                            style={primaryCta}
                        >
                            + Register server
                        </button>
                    ) : null}
                </header>

                {tab === 'servers' ? (
                    <ServersTab highlightId={highlightId} />
                ) : (
                    <McpToolCallAuditView />
                )}

                <RegisterServerDialog
                    open={showRegister}
                    onClose={() => setShowRegister(false)}
                    onCreated={(id) => {
                        setHighlightId(id);
                        setTab('servers');
                    }}
                />
            </section>
        </AdminShell>
    );
}

interface ServersTabProps {
    highlightId: number | null;
}

function ServersTab({ highlightId }: ServersTabProps) {
    const servers = useMcpServers();
    const handshake = useHandshakeMcpServer();
    const updateTools = useUpdateMcpServerTools();
    const disable = useDisableMcpServer();
    const remove = useDeleteMcpServer();

    const dataState = servers.isLoading
        ? 'loading'
        : servers.isError
            ? 'error'
            : (servers.data?.length ?? 0) === 0
                ? 'empty'
                : 'ready';

    return (
        <div
            data-testid="admin-mcp-servers"
            data-state={dataState}
            aria-busy={servers.isLoading}
            style={{ display: 'flex', flexDirection: 'column', gap: 16 }}
        >
            {dataState === 'loading' ? (
                <Placeholder>Loading servers…</Placeholder>
            ) : null}

            {dataState === 'error' ? (
                <Placeholder data-testid="admin-mcp-servers-error" role="alert" tone="error">
                    {servers.error?.message ?? 'Failed to load MCP servers'}
                </Placeholder>
            ) : null}

            {dataState === 'empty' ? (
                <Placeholder data-testid="admin-mcp-servers-empty">
                    <p style={{ margin: 0 }}>No MCP servers registered yet.</p>
                    <p style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-2)' }}>
                        Use "Register server" to add a stdio/SSE/HTTP MCP endpoint for this tenant.
                    </p>
                </Placeholder>
            ) : null}

            {servers.data?.map((server) => (
                <ServerCard
                    key={server.id}
                    server={server}
                    highlighted={highlightId === server.id}
                    onHandshake={() => handshake.mutate(server.id)}
                    onSaveTools={(enabled) =>
                        updateTools.mutate({ id: server.id, enabledTools: enabled })
                    }
                    onDisable={() => disable.mutate(server.id)}
                    onDelete={() => {
                        if (
                            window.confirm(
                                `Delete MCP server "${server.name}"? This cannot be undone.`,
                            )
                        ) {
                            remove.mutate(server.id);
                        }
                    }}
                    handshakeBusy={handshake.isPending && handshake.variables === server.id}
                    saveToolsBusy={
                        updateTools.isPending && updateTools.variables?.id === server.id
                    }
                    disableBusy={disable.isPending && disable.variables === server.id}
                    deleteBusy={remove.isPending && remove.variables === server.id}
                />
            ))}
        </div>
    );
}

interface ServerCardProps {
    server: McpServerEntry;
    highlighted: boolean;
    onHandshake: () => void;
    onSaveTools: (enabledTools: string[]) => void;
    onDisable: () => void;
    onDelete: () => void;
    handshakeBusy: boolean;
    saveToolsBusy: boolean;
    disableBusy: boolean;
    deleteBusy: boolean;
}

function ServerCard({
    server,
    highlighted,
    onHandshake,
    onSaveTools,
    onDisable,
    onDelete,
    handshakeBusy,
    saveToolsBusy,
    disableBusy,
    deleteBusy,
}: ServerCardProps) {
    return (
        <article
            data-testid={`admin-mcp-server-${server.id}`}
            data-highlighted={highlighted ? 'true' : 'false'}
            style={{
                border: `1px solid ${highlighted ? 'var(--accent-bg)' : 'var(--border-1)'}`,
                borderRadius: 14,
                padding: 18,
                background: 'var(--bg-1)',
                display: 'flex',
                flexDirection: 'column',
                gap: 12,
                boxShadow: highlighted ? '0 0 0 2px var(--accent-bg, rgba(59,130,246,0.4))' : 'none',
            }}
        >
            <header
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    flexWrap: 'wrap',
                    gap: 12,
                }}
            >
                <h3 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>{server.name}</h3>
                <code
                    style={{
                        fontFamily: 'var(--font-mono, ui-monospace)',
                        fontSize: 11.5,
                        color: 'var(--fg-2)',
                        background: 'var(--bg-2)',
                        padding: '2px 8px',
                        borderRadius: 6,
                    }}
                >
                    {server.transport} · {server.endpoint}
                </code>
                <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                    <button
                        type="button"
                        data-testid={`admin-mcp-server-${server.id}-disable`}
                        onClick={onDisable}
                        disabled={disableBusy || server.status === 'disabled'}
                        style={secondaryCta}
                    >
                        {server.status === 'disabled' ? 'Disabled' : 'Disable'}
                    </button>
                    <button
                        type="button"
                        data-testid={`admin-mcp-server-${server.id}-delete`}
                        onClick={onDelete}
                        disabled={deleteBusy}
                        style={dangerCta}
                    >
                        Delete
                    </button>
                </div>
            </header>

            <HandshakeStatus server={server} onRetry={onHandshake} busy={handshakeBusy} />

            <ToolMatrix server={server} onSave={onSaveTools} busy={saveToolsBusy} />
        </article>
    );
}

interface TabButtonProps {
    id: 'servers' | 'audit';
    current: 'servers' | 'audit';
    onSelect: (id: 'servers' | 'audit') => void;
    children: React.ReactNode;
}

function TabButton({ id, current, onSelect, children }: TabButtonProps) {
    const isCurrent = current === id;
    return (
        <button
            type="button"
            role="tab"
            aria-selected={isCurrent}
            data-testid={`admin-mcp-tools-tab-${id}`}
            onClick={() => onSelect(id)}
            style={{
                padding: '6px 14px',
                borderRadius: 8,
                border: isCurrent ? '1px solid var(--accent-bg)' : '1px solid var(--border-2)',
                background: isCurrent ? 'var(--accent-bg)' : 'transparent',
                color: isCurrent ? 'var(--accent-fg)' : 'var(--fg-1)',
                cursor: 'pointer',
                fontSize: 13,
                fontWeight: 600,
            }}
        >
            {children}
        </button>
    );
}

interface PlaceholderProps {
    children: React.ReactNode;
    tone?: 'normal' | 'error';
    [key: `data-${string}`]: string | undefined;
    role?: string;
}

function Placeholder({ children, tone = 'normal', role, ...dataProps }: PlaceholderProps) {
    return (
        <div
            role={role}
            {...dataProps}
            style={{
                padding: 28,
                textAlign: 'center',
                color: tone === 'error' ? 'var(--danger-fg)' : 'var(--fg-2)',
                background: tone === 'error' ? 'rgba(239,68,68,0.08)' : 'var(--bg-2)',
                border:
                    tone === 'error'
                        ? '1px solid rgba(239,68,68,0.3)'
                        : '1px dashed var(--border-2)',
                borderRadius: 14,
                display: 'flex',
                flexDirection: 'column',
                gap: 6,
            }}
        >
            {children}
        </div>
    );
}

const primaryCta: React.CSSProperties = {
    padding: '8px 14px',
    borderRadius: 8,
    border: '1px solid transparent',
    background: 'var(--accent-bg)',
    color: 'var(--accent-fg)',
    cursor: 'pointer',
    fontSize: 13,
    fontWeight: 600,
};

const secondaryCta: React.CSSProperties = {
    padding: '6px 12px',
    borderRadius: 8,
    border: '1px solid var(--border-2)',
    background: 'var(--bg-2)',
    color: 'var(--fg-1)',
    cursor: 'pointer',
    fontSize: 12.5,
    fontWeight: 600,
};

const dangerCta: React.CSSProperties = {
    padding: '6px 12px',
    borderRadius: 8,
    border: '1px solid rgba(239,68,68,0.3)',
    background: 'rgba(239,68,68,0.08)',
    color: 'var(--danger-fg)',
    cursor: 'pointer',
    fontSize: 12.5,
    fontWeight: 600,
};
