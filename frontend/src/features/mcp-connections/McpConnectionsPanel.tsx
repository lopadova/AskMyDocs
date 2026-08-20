import { useMemo, useState, type CSSProperties, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toAdminError } from '../admin/shared/errors';
import {
    mcpConnectionsApi,
    type CreateMcpConnectionPayload,
    type McpConnectionDto,
    type McpConnectionScope,
} from './mcp-connections.api';

const blankForm: CreateMcpConnectionPayload = {
    name: '',
    label: '',
    endpoint: '',
    transport: 'auto',
    project_key: null,
    bearer: '',
};

export function McpConnectionsPanel({ scope }: { scope: McpConnectionScope }) {
    const queryClient = useQueryClient();
    const queryKey = ['mcp-connections', scope] as const;
    const [form, setForm] = useState<CreateMcpConnectionPayload>(blankForm);
    const [formOpen, setFormOpen] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const oauthResult = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('mcp');
    const query = useQuery({
        queryKey,
        queryFn: () => mcpConnectionsApi.list(scope),
        retry: false,
        refetchOnWindowFocus: false,
    });
    const refresh = async () => queryClient.invalidateQueries({ queryKey });
    const create = useMutation({
        mutationFn: (payload: CreateMcpConnectionPayload) => mcpConnectionsApi.create(scope, payload),
        onSuccess: async () => {
            setForm(blankForm);
            setFormOpen(false);
            setError(null);
            await refresh();
        },
        onError: (cause) => setError(toAdminError(cause).message),
    });
    const action = useMutation({
        mutationFn: async (request: { kind: 'discover' | 'disconnect' | 'remove' | 'sync-resources'; id: string }) => {
            if (request.kind === 'discover') return mcpConnectionsApi.discover(scope, request.id);
            if (request.kind === 'disconnect') return mcpConnectionsApi.disconnect(scope, request.id);
            if (request.kind === 'sync-resources') return mcpConnectionsApi.syncResources(request.id);
            return mcpConnectionsApi.remove(scope, request.id);
        },
        onSuccess: refresh,
        onError: (cause) => setError(toAdminError(cause).message),
    });
    const toolMutation = useMutation({
        mutationFn: (request: { connectionId: string; toolId: number; enabled: boolean }) =>
            mcpConnectionsApi.setTool(scope, request.connectionId, request.toolId, request.enabled),
        onSuccess: refresh,
        onError: (cause) => setError(toAdminError(cause).message),
    });
    const resourceMutation = useMutation({
        mutationFn: (request: { connectionId: string; resourceId: number; enabled: boolean }) =>
            mcpConnectionsApi.setResource(request.connectionId, request.resourceId, request.enabled),
        onSuccess: refresh,
        onError: (cause) => setError(toAdminError(cause).message),
    });
    const oauth = useMutation({
        mutationFn: (connectionId: string) => mcpConnectionsApi.beginOAuth(scope, connectionId),
        onSuccess: (url) => window.location.assign(url),
        onError: (cause) => setError(toAdminError(cause).message),
    });

    const connections = useMemo(() => query.data ?? [], [query.data]);
    const state = query.isLoading ? 'loading' : query.isError ? 'error' : 'ready';
    const title = scope === 'shared' ? 'MCP live connections' : 'Connected Apps';
    const subtitle = scope === 'shared'
        ? 'Shared MCP servers provide fresh chat tools and selected resources for the project knowledge base.'
        : 'Connect your own MCP apps. Personal credentials and tools are visible only to you.';

    function submit(event: FormEvent) {
        event.preventDefault();
        setError(null);
        create.mutate({
            ...form,
            label: form.label?.trim() || form.name,
            project_key: form.project_key?.trim() || null,
            bearer: form.bearer?.trim() || undefined,
        });
    }

    return (
        <section data-testid={`mcp-connections-${scope}`} data-state={state} style={panelStyle}>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 16, flexWrap: 'wrap' }}>
                <div style={{ flex: 1, minWidth: 240 }}>
                    <h2 style={{ margin: 0, color: 'var(--fg-0)', fontSize: 18 }}>{title}</h2>
                    <p style={{ margin: '6px 0 0', color: 'var(--fg-3)', fontSize: 13, lineHeight: 1.5 }}>{subtitle}</p>
                </div>
                <button type="button" className="focus-ring" onClick={() => setFormOpen((open) => !open)} style={primaryButtonStyle}>
                    {formOpen ? 'Cancel' : 'Add MCP connection'}
                </button>
            </div>

            {oauthResult && (
                <div role="status" data-testid="mcp-oauth-result" style={successStyle}>
                    {oauthResult === 'connected' ? 'OAuth connection completed.' : 'OAuth completed, but tool discovery needs attention.'}
                </div>
            )}

            {error && <div role="alert" style={errorStyle}>{error}</div>}

            {formOpen && (
                <form onSubmit={submit} data-testid={`mcp-connection-form-${scope}`} style={formStyle}>
                    <Field label="Name">
                        <input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} style={inputStyle} />
                    </Field>
                    <Field label="Label">
                        <input value={form.label ?? ''} onChange={(event) => setForm({ ...form, label: event.target.value })} style={inputStyle} />
                    </Field>
                    <Field label="MCP endpoint">
                        <input required type="url" placeholder="https://mcp.example.com/rpc" value={form.endpoint} onChange={(event) => setForm({ ...form, endpoint: event.target.value })} style={inputStyle} />
                    </Field>
                    <Field label="Transport">
                        <select value={form.transport} onChange={(event) => setForm({ ...form, transport: event.target.value as CreateMcpConnectionPayload['transport'] })} style={inputStyle}>
                            <option value="auto">Auto</option>
                            <option value="streamable_http">Streamable HTTP</option>
                            <option value="legacy_sse">Legacy SSE</option>
                        </select>
                    </Field>
                    <Field label="Project (optional)">
                        <input value={form.project_key ?? ''} onChange={(event) => setForm({ ...form, project_key: event.target.value })} style={inputStyle} />
                    </Field>
                    <Field label="Bearer token (optional)">
                        <input type="password" autoComplete="new-password" value={form.bearer ?? ''} onChange={(event) => setForm({ ...form, bearer: event.target.value })} style={inputStyle} />
                    </Field>
                    <button type="submit" disabled={create.isPending} style={primaryButtonStyle}>
                        {create.isPending ? 'Connecting…' : 'Connect and discover'}
                    </button>
                </form>
            )}

            {query.isLoading && <div role="status" style={emptyStyle}>Loading MCP connections…</div>}
            {query.isError && (
                <div role="alert" style={emptyStyle}>
                    MCP connections are unavailable. The feature may still be disabled for this environment.{' '}
                    <button type="button" onClick={() => void query.refetch()} style={linkButtonStyle}>Retry</button>
                </div>
            )}
            {!query.isLoading && !query.isError && connections.length === 0 && (
                <div role="status" style={emptyStyle}>No MCP connections yet.</div>
            )}
            {connections.map((connection) => (
                <ConnectionCard
                    key={connection.public_id}
                    connection={connection}
                    busy={action.isPending || toolMutation.isPending || resourceMutation.isPending || oauth.isPending}
                    onAction={(kind) => action.mutate({ kind, id: connection.public_id })}
                    onOAuth={() => oauth.mutate(connection.public_id)}
                    onTool={(toolId, enabled) => toolMutation.mutate({ connectionId: connection.public_id, toolId, enabled })}
                    onResource={(resourceId, enabled) => resourceMutation.mutate({ connectionId: connection.public_id, resourceId, enabled })}
                />
            ))}
        </section>
    );
}

function ConnectionCard({
    connection,
    busy,
    onAction,
    onOAuth,
    onTool,
    onResource,
}: {
    connection: McpConnectionDto;
    busy: boolean;
    onAction: (kind: 'discover' | 'disconnect' | 'remove' | 'sync-resources') => void;
    onOAuth: () => void;
    onTool: (toolId: number, enabled: boolean) => void;
    onResource: (resourceId: number, enabled: boolean) => void;
}) {
    const oauthRequired = connection.server.auth_mode === 'oauth'
        || connection.status === 'reauthorization_required'
        || connection.error_json?.authorization_required === true;

    return (
        <article data-testid={`mcp-connection-${connection.public_id}`} style={cardStyle}>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, flexWrap: 'wrap' }}>
                <div style={{ flex: 1, minWidth: 220 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <strong style={{ color: 'var(--fg-0)' }}>{connection.label}</strong>
                        <StatusBadge status={connection.status} />
                        <span style={mutedBadgeStyle}>{connection.server.negotiated_era ?? 'not negotiated'}</span>
                    </div>
                    <div style={{ marginTop: 6, color: 'var(--fg-3)', fontSize: 12, overflowWrap: 'anywhere' }}>
                        {connection.server.endpoint}
                    </div>
                    <div style={{ marginTop: 4, color: 'var(--fg-3)', fontSize: 12 }}>
                        Protocol {connection.server.negotiated_version ?? '—'} · Project {connection.project_key ?? 'tenant default'}
                    </div>
                </div>
                <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap' }}>
                    {oauthRequired && <button type="button" disabled={busy} onClick={onOAuth} style={smallButtonStyle}>Connect OAuth</button>}
                    <button type="button" disabled={busy} onClick={() => onAction('discover')} style={smallButtonStyle}>Discover</button>
                    {connection.mode === 'shared' && connection.resources.some((resource) => resource.enabled) && (
                        <button type="button" disabled={busy || connection.connector_installation_id === null} onClick={() => onAction('sync-resources')} style={smallButtonStyle}>
                            Sync resources
                        </button>
                    )}
                    <button type="button" disabled={busy} onClick={() => onAction('disconnect')} style={smallButtonStyle}>Disconnect</button>
                    <button type="button" disabled={busy} onClick={() => onAction('remove')} style={dangerButtonStyle}>Remove</button>
                </div>
            </div>
            <div style={{ marginTop: 14, borderTop: '1px solid var(--hairline)', paddingTop: 12 }}>
                <div style={{ fontSize: 12, fontWeight: 650, color: 'var(--fg-2)', marginBottom: 8 }}>
                    Tools ({connection.tools.length})
                </div>
                {connection.tools.length === 0 ? (
                    <div style={{ color: 'var(--fg-3)', fontSize: 12 }}>The server reported an empty catalog.</div>
                ) : connection.tools.map((tool) => (
                    <label key={tool.id} style={toolRowStyle}>
                        <input type="checkbox" checked={tool.enabled} disabled={busy || tool.removed_at != null} onChange={(event) => onTool(tool.id, event.target.checked)} />
                        <span style={{ flex: 1, minWidth: 180 }}>
                            <strong style={{ display: 'block', color: 'var(--fg-1)', fontSize: 12.5 }}>{tool.title ?? tool.remote_name}</strong>
                            <span style={{ color: 'var(--fg-3)', fontSize: 11.5 }}>{tool.local_name}</span>
                        </span>
                        <span style={riskStyle(tool.risk)}>{tool.risk}</span>
                        {tool.confirmation_required && <span style={mutedBadgeStyle}>confirmation</span>}
                        {tool.removed_at && <span style={mutedBadgeStyle}>removed</span>}
                    </label>
                ))}
            </div>
            <div style={{ marginTop: 14, borderTop: '1px solid var(--hairline)', paddingTop: 12 }}>
                <div style={{ fontSize: 12, fontWeight: 650, color: 'var(--fg-2)', marginBottom: 8 }}>
                    Resources ({connection.resources.length})
                </div>
                {connection.resources.length === 0 ? (
                    <div style={{ color: 'var(--fg-3)', fontSize: 12 }}>The server reported no ingestible resources.</div>
                ) : connection.resources.map((resource) => (
                    <label key={resource.id} style={toolRowStyle}>
                        {connection.mode === 'shared' ? (
                            <input type="checkbox" checked={resource.enabled} disabled={busy || resource.removed_at != null} onChange={(event) => onResource(resource.id, event.target.checked)} />
                        ) : <span style={mutedBadgeStyle}>catalog only</span>}
                        <span style={{ flex: 1, minWidth: 180 }}>
                            <strong style={{ display: 'block', color: 'var(--fg-1)', fontSize: 12.5 }}>{resource.title ?? resource.name ?? resource.uri}</strong>
                            <span style={{ color: 'var(--fg-3)', fontSize: 11.5, overflowWrap: 'anywhere' }}>{resource.uri}</span>
                        </span>
                        {resource.mime_type && <span style={mutedBadgeStyle}>{resource.mime_type}</span>}
                        {resource.last_ingested_at && <span style={mutedBadgeStyle}>ingested</span>}
                        {resource.ingest_error_json && <span style={{ ...mutedBadgeStyle, color: '#fca5a5' }}>error</span>}
                        {resource.removed_at && <span style={mutedBadgeStyle}>removed</span>}
                    </label>
                ))}
            </div>
        </article>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return <label style={{ display: 'grid', gap: 5, color: 'var(--fg-2)', fontSize: 12 }}>{label}{children}</label>;
}

function StatusBadge({ status }: { status: string }) {
    return <span style={{ ...mutedBadgeStyle, color: status === 'active' ? '#86efac' : status === 'errored' ? '#fca5a5' : '#fde68a' }}>{status}</span>;
}

const panelStyle: CSSProperties = { display: 'grid', gap: 14, border: '1px solid var(--hairline)', borderRadius: 14, background: 'var(--bg-1)', padding: 18 };
const cardStyle: CSSProperties = { border: '1px solid var(--hairline)', borderRadius: 11, background: 'var(--bg-0)', padding: 14 };
const formStyle: CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))', gap: 12, padding: 14, borderRadius: 10, border: '1px solid var(--hairline)', background: 'var(--bg-0)', alignItems: 'end' };
const inputStyle: CSSProperties = { width: '100%', boxSizing: 'border-box', borderRadius: 8, border: '1px solid var(--hairline)', background: 'var(--bg-2)', color: 'var(--fg-0)', padding: '9px 10px', font: 'inherit' };
const primaryButtonStyle: CSSProperties = { border: '1px solid rgba(99,102,241,.55)', borderRadius: 8, background: 'rgba(99,102,241,.18)', color: 'var(--fg-0)', padding: '9px 13px', font: 'inherit', fontSize: 12.5, fontWeight: 650, cursor: 'pointer' };
const smallButtonStyle: CSSProperties = { ...primaryButtonStyle, padding: '6px 9px', fontSize: 11.5, background: 'var(--bg-2)', borderColor: 'var(--hairline)' };
const dangerButtonStyle: CSSProperties = { ...smallButtonStyle, color: '#fca5a5', borderColor: 'rgba(239,68,68,.35)' };
const linkButtonStyle: CSSProperties = { border: 0, background: 'transparent', color: 'var(--accent)', cursor: 'pointer' };
const emptyStyle: CSSProperties = { padding: 18, textAlign: 'center', color: 'var(--fg-3)', border: '1px dashed var(--hairline)', borderRadius: 10, fontSize: 12.5 };
const errorStyle: CSSProperties = { padding: 10, color: '#fca5a5', background: 'rgba(239,68,68,.08)', border: '1px solid rgba(239,68,68,.3)', borderRadius: 8, fontSize: 12.5 };
const successStyle: CSSProperties = { padding: 10, color: '#86efac', background: 'rgba(34,197,94,.08)', border: '1px solid rgba(34,197,94,.3)', borderRadius: 8, fontSize: 12.5 };
const mutedBadgeStyle: CSSProperties = { border: '1px solid var(--hairline)', borderRadius: 999, padding: '2px 7px', color: 'var(--fg-3)', fontSize: 10.5, whiteSpace: 'nowrap' };
const toolRowStyle: CSSProperties = { display: 'flex', alignItems: 'center', gap: 9, padding: '8px 0', borderTop: '1px solid color-mix(in srgb, var(--hairline) 65%, transparent)' };
function riskStyle(risk: string): CSSProperties { return { ...mutedBadgeStyle, color: risk === 'read' ? '#86efac' : risk === 'destructive' ? '#fca5a5' : '#fde68a' }; }
