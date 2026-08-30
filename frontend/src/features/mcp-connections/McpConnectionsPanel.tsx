import { useEffect, useMemo, useRef, useState, type CSSProperties, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '../../lib/auth-store';
import { modalBackdropStyle, modalPanelStyle } from '../admin/api-connectors/styles';
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
    auth_method: 'oauth',
    bearer: '',
};

export interface McpProjectOption {
    project_key: string;
    name?: string;
}

export interface McpConnectionsPanelProps {
    scope: McpConnectionScope;
    projects?: McpProjectOption[];
    projectsLoading?: boolean;
    projectsError?: boolean;
    /** Increment to open the create modal from an external add-connection tile. */
    createRequest?: number;
    /** Compact group rendering for the unified admin Connections hub. */
    embedded?: boolean;
}

export function McpConnectionsPanel({
    scope,
    projects,
    projectsLoading = false,
    projectsError = false,
    createRequest = 0,
    embedded = false,
}: McpConnectionsPanelProps) {
    const queryClient = useQueryClient();
    const queryKey = ['mcp-connections', scope] as const;
    const authProjects = useAuthStore((state) => state.projects);
    const projectOptions = useMemo<McpProjectOption[]>(
        () => projects ?? authProjects.map((project) => ({ project_key: project.project_key })),
        [authProjects, projects],
    );
    const [form, setForm] = useState<CreateMcpConnectionPayload>(blankForm);
    const [formOpen, setFormOpen] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const previousCreateRequest = useRef(createRequest);
    // Capture the callback result once. The URL is cleaned immediately below,
    // while the connections query can still trigger additional renders.
    const [oauthResult] = useState(() =>
        typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('mcp'),
    );
    const query = useQuery({
        queryKey,
        queryFn: () => mcpConnectionsApi.list(scope),
        retry: false,
        refetchOnWindowFocus: false,
    });
    const refresh = async () => queryClient.invalidateQueries({ queryKey });
    const create = useMutation({
        mutationFn: (payload: CreateMcpConnectionPayload) => mcpConnectionsApi.create(scope, payload),
        onSuccess: async (result) => {
            if (result.next_action?.type === 'oauth_redirect') {
                window.location.assign(result.next_action.authorization_url);
                return;
            }
            closeForm();
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

    useEffect(() => {
        if (!oauthResult) return;
        const url = new URL(window.location.href);
        url.searchParams.delete('mcp');
        url.searchParams.delete('mcp_connection');
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    }, [oauthResult]);

    useEffect(() => {
        if (createRequest === previousCreateRequest.current) return;
        previousCreateRequest.current = createRequest;
        setError(null);
        setFormOpen(true);
    }, [createRequest]);

    useEffect(() => {
        if (!formOpen) return;
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !create.isPending) closeForm();
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [create.isPending, formOpen]);

    const connections = useMemo(() => query.data ?? [], [query.data]);
    const state = query.isLoading ? 'loading' : query.isError ? 'error' : 'ready';
    const title = scope === 'shared' ? 'MCP connections' : 'Connected Apps';
    const subtitle = scope === 'shared'
        ? 'Live tools and resources exposed by remote MCP servers.'
        : 'Connect your own MCP apps. Personal credentials and tools are visible only to you.';

    function closeForm() {
        setForm(blankForm);
        setFormOpen(false);
        setError(null);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        setError(null);
        create.mutate({
            ...form,
            label: form.label?.trim() || form.name,
            project_key: form.project_key?.trim() || null,
            bearer: form.auth_method === 'bearer' ? form.bearer?.trim() || undefined : undefined,
            ui_destination: window.location.pathname,
        });
    }

    return (
        <section
            data-testid={`mcp-connections-${scope}`}
            data-state={state}
            style={embedded ? embeddedPanelStyle : panelStyle}
        >
            <div style={{ display: 'flex', alignItems: embedded ? 'center' : 'flex-start', gap: 12, flexWrap: 'wrap' }}>
                <div style={{ flex: 1, minWidth: 220 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <h2 style={embedded ? groupHeadingStyle : titleStyle}>{title}</h2>
                        <span data-testid="mcp-connections-count" style={countBadgeStyle}>{connections.length}</span>
                    </div>
                    {!embedded && <p style={subtitleStyle}>{subtitle}</p>}
                </div>
                {!embedded && (
                    <button type="button" className="focus-ring" onClick={() => setFormOpen(true)} style={primaryButtonStyle}>
                        Add MCP connection
                    </button>
                )}
            </div>

            {oauthResult && (
                <div
                    role={oauthResult === 'connected' ? 'status' : 'alert'}
                    data-testid="mcp-oauth-result"
                    style={oauthResult === 'connected' ? successStyle : oauthResult === 'oauth_denied' ? noticeStyle : errorStyle}
                >
                    {oauthMessage(oauthResult)}
                </div>
            )}

            {error && !formOpen && <div role="alert" style={errorStyle}>{error}</div>}

            {query.isLoading && <div role="status" style={emptyStyle}>Loading MCP connections…</div>}
            {query.isError && (
                <div role="alert" style={emptyStyle}>
                    MCP connections are unavailable. The feature may still be disabled for this environment.{' '}
                    <button type="button" onClick={() => void query.refetch()} style={linkButtonStyle}>Retry</button>
                </div>
            )}
            {!query.isLoading && !query.isError && connections.length === 0 && (
                <div role="status" style={emptyStyle}>No MCP connections yet. Add one from the connection gallery above.</div>
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

            {formOpen && (
                <div
                    data-testid="mcp-connection-form-backdrop"
                    onClick={(event) => {
                        if (event.target === event.currentTarget && !create.isPending) closeForm();
                    }}
                    style={modalBackdropStyle()}
                >
                    <form
                        onSubmit={submit}
                        data-testid={`mcp-connection-form-${scope}`}
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="mcp-connection-form-title"
                        style={{ ...modalPanelStyle(720), minWidth: 0, padding: 0, gap: 0, overflow: 'hidden' }}
                    >
                        <div style={modalHeaderStyle}>
                            <div>
                                <h2 id="mcp-connection-form-title" style={{ margin: 0, color: 'var(--fg-0)', fontSize: 17 }}>
                                    New MCP connection
                                </h2>
                                <p style={{ margin: '4px 0 0', color: 'var(--fg-3)', fontSize: 12.5 }}>
                                    Connect a server, authenticate securely and discover its capabilities.
                                </p>
                            </div>
                            <button
                                type="button"
                                aria-label="Close MCP connection form"
                                disabled={create.isPending}
                                onClick={closeForm}
                                style={closeButtonStyle}
                            >
                                ×
                            </button>
                        </div>

                        <div style={modalBodyStyle}>
                            {error && <div role="alert" style={errorStyle}>{error}</div>}

                            <div style={twoColumnStyle}>
                                <Field label="Name">
                                    <input required autoFocus value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} style={inputStyle} />
                                </Field>
                                <Field label="Label">
                                    <input value={form.label ?? ''} onChange={(event) => setForm({ ...form, label: event.target.value })} style={inputStyle} />
                                </Field>
                            </div>

                            <Field label="MCP endpoint">
                                <input required type="url" placeholder="https://mcp.example.com/rpc" value={form.endpoint} onChange={(event) => setForm({ ...form, endpoint: event.target.value })} style={inputStyle} />
                            </Field>

                            <Field label="Project (optional)" hint={projectsError ? 'Projects could not be loaded.' : 'Controls where shared resources are indexed.'}>
                                <select
                                    value={form.project_key ?? ''}
                                    disabled={projectsLoading}
                                    onChange={(event) => setForm({ ...form, project_key: event.target.value || null })}
                                    style={inputStyle}
                                >
                                    <option value="">{projectsLoading ? 'Loading projects…' : 'Tenant default'}</option>
                                    {projectOptions.map((project) => (
                                        <option key={project.project_key} value={project.project_key}>
                                            {project.name ? `${project.name} · ${project.project_key}` : project.project_key}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <fieldset style={authFieldsetStyle}>
                                <legend style={authLegendStyle}>Authentication</legend>
                                <div style={authGridStyle}>
                                    <AuthChoice
                                        checked={form.auth_method === 'oauth'}
                                        label="OAuth"
                                        description="Sign in securely"
                                        onChange={() => setForm({ ...form, auth_method: 'oauth', bearer: '' })}
                                    />
                                    <AuthChoice
                                        checked={form.auth_method === 'bearer'}
                                        label="Bearer token"
                                        description="Use an existing token"
                                        onChange={() => setForm({ ...form, auth_method: 'bearer' })}
                                    />
                                    <AuthChoice
                                        checked={form.auth_method === 'none'}
                                        label="No authentication"
                                        description="Public endpoints only"
                                        onChange={() => setForm({ ...form, auth_method: 'none', bearer: '' })}
                                    />
                                </div>
                            </fieldset>

                            {form.auth_method === 'bearer' && (
                                <Field label="Bearer token">
                                    <input required type="password" autoComplete="new-password" value={form.bearer ?? ''} onChange={(event) => setForm({ ...form, bearer: event.target.value })} style={inputStyle} />
                                </Field>
                            )}

                            <details style={advancedStyle}>
                                <summary style={advancedSummaryStyle}>Advanced settings</summary>
                                <div style={{ marginTop: 12, maxWidth: 330 }}>
                                    <Field label="Transport">
                                        <select value={form.transport} onChange={(event) => setForm({ ...form, transport: event.target.value as CreateMcpConnectionPayload['transport'] })} style={inputStyle}>
                                            <option value="auto">Auto (recommended)</option>
                                            <option value="streamable_http">Streamable HTTP</option>
                                            <option value="legacy_sse">Legacy SSE</option>
                                        </select>
                                    </Field>
                                </div>
                            </details>
                        </div>

                        <div style={modalFooterStyle}>
                            <button type="button" disabled={create.isPending} onClick={closeForm} style={secondaryButtonStyle}>Cancel</button>
                            <button type="submit" disabled={create.isPending} style={primaryButtonStyle}>
                                {create.isPending ? 'Connecting…' : form.auth_method === 'oauth' ? 'Continue with OAuth' : 'Connect and discover'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
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
    const [expanded, setExpanded] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const oauthRequired = connection.server.auth_mode === 'oauth'
        || connection.status === 'reauthorization_required'
        || connection.error_json?.authorization_required === true;
    const requiresOAuthAction = oauthRequired && connection.status !== 'active';
    const enabledTools = connection.tools.filter((tool) => tool.enabled && tool.removed_at == null).length;
    const enabledResources = connection.resources.filter((resource) => resource.enabled && resource.removed_at == null).length;

    return (
        <article data-testid={`mcp-connection-${connection.public_id}`} style={cardStyle}>
            <div style={cardHeaderStyle}>
                <span aria-hidden="true" style={mcpAvatarStyle}>
                    <McpIcon />
                </span>
                <div style={{ flex: 1, minWidth: 210 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 7, flexWrap: 'wrap' }}>
                        <strong style={{ color: 'var(--fg-0)', fontSize: 13.5 }}>{connection.label}</strong>
                        <StatusBadge status={connection.status} />
                        <span style={mutedBadgeStyle}>{connection.server.negotiated_era ?? 'not negotiated'}</span>
                    </div>
                    <div style={endpointStyle}>{hostOf(connection.server.endpoint)}</div>
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8 }}>
                        <SummaryBadge label="Tools" value={connection.tools.length} active={enabledTools} />
                        <SummaryBadge label="Resources" value={connection.resources.length} active={enabledResources} />
                        <span style={summaryBadgeStyle}>Project · {connection.project_key ?? 'Tenant default'}</span>
                    </div>
                </div>

                <div style={cardActionsStyle}>
                    {requiresOAuthAction && (
                        <button type="button" disabled={busy} onClick={onOAuth} style={primarySmallButtonStyle}>
                            Connect OAuth
                        </button>
                    )}
                    <button
                        type="button"
                        className="focus-ring"
                        aria-expanded={expanded}
                        aria-label={expanded ? `Collapse ${connection.label}` : `Expand ${connection.label}`}
                        onClick={() => setExpanded((open) => !open)}
                        style={iconActionStyle}
                    >
                        <ChevronIcon expanded={expanded} />
                    </button>
                    <div style={{ position: 'relative' }}>
                        <button
                            type="button"
                            className="focus-ring"
                            aria-label={`More actions for ${connection.label}`}
                            aria-expanded={menuOpen}
                            onClick={() => setMenuOpen((open) => !open)}
                            style={iconActionStyle}
                        >
                            <span aria-hidden="true" style={{ fontSize: 18, lineHeight: 1 }}>⋯</span>
                        </button>
                        {menuOpen && (
                            <div role="menu" style={menuStyle}>
                                {oauthRequired && connection.status === 'active' && (
                                    <MenuButton disabled={busy} onClick={() => { setMenuOpen(false); onOAuth(); }}>Reconnect OAuth</MenuButton>
                                )}
                                <MenuButton disabled={busy} onClick={() => { setMenuOpen(false); onAction('discover'); }}>Refresh catalog</MenuButton>
                                {connection.mode === 'shared' && connection.resources.some((resource) => resource.enabled) && (
                                    <MenuButton disabled={busy || connection.connector_installation_id === null} onClick={() => { setMenuOpen(false); onAction('sync-resources'); }}>Sync resources</MenuButton>
                                )}
                                <MenuButton disabled={busy} onClick={() => { setMenuOpen(false); onAction('disconnect'); }}>Disconnect</MenuButton>
                                <MenuButton danger disabled={busy} onClick={() => { setMenuOpen(false); onAction('remove'); }}>Remove connection</MenuButton>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {expanded && (
                <div data-testid={`mcp-connection-${connection.public_id}-details`} style={detailsStyle}>
                    <div style={metadataBarStyle}>
                        <span>Protocol {connection.server.negotiated_version ?? '—'}</span>
                        <span>Transport {connection.server.transport}</span>
                        <span title={connection.server.endpoint} style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{connection.server.endpoint}</span>
                    </div>
                    <CapabilityList
                        title="Tools"
                        count={connection.tools.length}
                        empty="The server reported an empty tool catalog."
                    >
                        {connection.tools.map((tool) => (
                            <label key={tool.id} style={toolRowStyle}>
                                <input type="checkbox" checked={tool.enabled} disabled={busy || tool.removed_at != null} onChange={(event) => onTool(tool.id, event.target.checked)} />
                                <span style={{ flex: 1, minWidth: 180 }}>
                                    <strong style={rowTitleStyle}>{tool.title ?? tool.remote_name}</strong>
                                    <span style={rowSubtitleStyle}>{tool.local_name}</span>
                                </span>
                                <span style={riskStyle(tool.risk)}>{tool.risk}</span>
                                {tool.confirmation_required && <span style={mutedBadgeStyle}>confirmation</span>}
                                {tool.removed_at && <span style={mutedBadgeStyle}>removed</span>}
                            </label>
                        ))}
                    </CapabilityList>
                    <CapabilityList
                        title="Resources"
                        count={connection.resources.length}
                        empty="The server reported no ingestible resources."
                    >
                        {connection.resources.map((resource) => (
                            <label key={resource.id} style={toolRowStyle}>
                                {connection.mode === 'shared' ? (
                                    <input type="checkbox" checked={resource.enabled} disabled={busy || resource.removed_at != null} onChange={(event) => onResource(resource.id, event.target.checked)} />
                                ) : <span style={mutedBadgeStyle}>catalog only</span>}
                                <span style={{ flex: 1, minWidth: 180 }}>
                                    <strong style={rowTitleStyle}>{resource.title ?? resource.name ?? resource.uri}</strong>
                                    <span style={{ ...rowSubtitleStyle, overflowWrap: 'anywhere' }}>{resource.uri}</span>
                                </span>
                                {resource.mime_type && <span style={mutedBadgeStyle}>{resource.mime_type}</span>}
                                {resource.last_ingested_at && <span style={mutedBadgeStyle}>ingested</span>}
                                {resource.ingest_error_json && <span style={{ ...mutedBadgeStyle, color: '#fca5a5' }}>error</span>}
                                {resource.removed_at && <span style={mutedBadgeStyle}>removed</span>}
                            </label>
                        ))}
                    </CapabilityList>
                </div>
            )}
        </article>
    );
}

function CapabilityList({ title, count, empty, children }: { title: string; count: number; empty: string; children: React.ReactNode }) {
    return (
        <section style={capabilityStyle}>
            <div style={capabilityHeadingStyle}>
                <span>{title}</span>
                <span style={countBadgeStyle}>{count}</span>
            </div>
            {count === 0 ? <div style={emptyCapabilityStyle}>{empty}</div> : children}
        </section>
    );
}

function SummaryBadge({ label, value, active }: { label: string; value: number; active: number }) {
    const noun = value === 1 ? label.replace(/s$/, '') : label;
    return <span style={summaryBadgeStyle}>{value} {noun.toLowerCase()} · {active} enabled</span>;
}

function MenuButton({ children, danger = false, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & { danger?: boolean }) {
    return <button type="button" role="menuitem" style={{ ...menuButtonStyle, ...(danger ? { color: '#fca5a5' } : {}) }} {...props}>{children}</button>;
}

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
    return (
        <label style={{ display: 'grid', gap: 5, color: 'var(--fg-2)', fontSize: 12 }}>
            <span>{label}</span>
            {children}
            {hint && <span style={{ color: 'var(--fg-3)', fontSize: 10.5 }}>{hint}</span>}
        </label>
    );
}

function AuthChoice({ checked, label, description, onChange }: { checked: boolean; label: string; description: string; onChange: () => void }) {
    return (
        <label style={{ ...authChoiceStyle, ...(checked ? authChoiceSelectedStyle : {}) }}>
            <input type="radio" name="mcp-auth-method" checked={checked} onChange={onChange} />
            <span>
                <strong style={{ display: 'block', color: 'var(--fg-1)', fontSize: 12.5 }}>{label}</strong>
                <span style={{ display: 'block', marginTop: 2, color: 'var(--fg-3)', fontSize: 11, lineHeight: 1.35 }}>{description}</span>
            </span>
        </label>
    );
}

function oauthMessage(status: string): string {
    if (status === 'connected') return 'OAuth connection completed. Tools and resources are ready.';
    if (status === 'discovery_failed') return 'Sign-in completed, but the MCP catalog could not be loaded. You can retry discovery from the connection.';
    if (status === 'oauth_denied') return 'OAuth sign-in was cancelled. No credentials were saved.';
    return 'OAuth sign-in could not be completed. Please try again.';
}

function StatusBadge({ status }: { status: string }) {
    const color = status === 'active' ? '#86efac' : status === 'errored' || status === 'reauthorization_required' ? '#fca5a5' : '#fde68a';
    return <span style={{ ...mutedBadgeStyle, color, textTransform: 'capitalize' }}>{status.replaceAll('_', ' ')}</span>;
}

function hostOf(endpoint: string): string {
    try {
        return new URL(endpoint).host || endpoint;
    } catch {
        return endpoint;
    }
}

function McpIcon() {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="6" cy="7" r="2.5" />
            <circle cx="18" cy="7" r="2.5" />
            <circle cx="12" cy="17" r="2.5" />
            <path d="m8.2 8.2 2.6 6.5M15.8 8.2l-2.6 6.5M8.5 7h7" />
        </svg>
    );
}

function ChevronIcon({ expanded }: { expanded: boolean }) {
    return (
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true" style={{ transform: expanded ? 'rotate(180deg)' : undefined, transition: 'transform .16s ease' }}>
            <path d="m4 6 4 4 4-4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

const panelStyle: CSSProperties = { display: 'grid', gap: 14, border: '1px solid var(--hairline)', borderRadius: 14, background: 'var(--bg-1)', padding: 18 };
const embeddedPanelStyle: CSSProperties = { display: 'grid', gap: 10 };
const titleStyle: CSSProperties = { margin: 0, color: 'var(--fg-0)', fontSize: 18 };
const subtitleStyle: CSSProperties = { margin: '6px 0 0', color: 'var(--fg-3)', fontSize: 13, lineHeight: 1.5 };
const groupHeadingStyle: CSSProperties = { margin: 0, fontSize: 12, fontWeight: 650, textTransform: 'uppercase', letterSpacing: '.055em', color: 'var(--fg-3)' };
const cardStyle: CSSProperties = { position: 'relative', border: '1px solid var(--hairline)', borderRadius: 12, background: 'var(--bg-1)', boxShadow: '0 1px 0 rgba(255,255,255,.02)' };
const cardHeaderStyle: CSSProperties = { display: 'flex', alignItems: 'center', gap: 12, padding: 13, flexWrap: 'wrap' };
const cardActionsStyle: CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, marginLeft: 'auto' };
const mcpAvatarStyle: CSSProperties = { width: 36, height: 36, display: 'grid', placeItems: 'center', flex: 'none', borderRadius: 9, color: '#67e8f9', background: 'rgba(34,211,238,.09)', border: '1px solid rgba(34,211,238,.2)' };
const endpointStyle: CSSProperties = { marginTop: 4, color: 'var(--fg-3)', fontSize: 11.5, fontFamily: 'var(--font-mono)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' };
const summaryBadgeStyle: CSSProperties = { borderRadius: 999, padding: '3px 8px', background: 'var(--bg-2)', color: 'var(--fg-2)', fontSize: 10.5, whiteSpace: 'nowrap' };
const detailsStyle: CSSProperties = { display: 'grid', gap: 12, padding: '0 13px 13px', borderTop: '1px solid var(--hairline)' };
const metadataBarStyle: CSSProperties = { display: 'flex', gap: 14, padding: '10px 0 0', color: 'var(--fg-3)', fontSize: 10.5, fontFamily: 'var(--font-mono)', overflow: 'hidden' };
const capabilityStyle: CSSProperties = { border: '1px solid var(--hairline)', borderRadius: 9, background: 'var(--bg-0)', padding: '0 10px' };
const capabilityHeadingStyle: CSSProperties = { display: 'flex', alignItems: 'center', gap: 7, minHeight: 36, color: 'var(--fg-1)', fontSize: 12, fontWeight: 650 };
const emptyCapabilityStyle: CSSProperties = { padding: '0 0 10px', color: 'var(--fg-3)', fontSize: 11.5 };
const countBadgeStyle: CSSProperties = { borderRadius: 999, padding: '1px 7px', color: 'var(--fg-2)', background: 'var(--bg-2)', fontSize: 10.5, fontWeight: 600 };
const inputStyle: CSSProperties = { width: '100%', boxSizing: 'border-box', borderRadius: 8, border: '1px solid var(--hairline)', background: 'var(--bg-2)', color: 'var(--fg-0)', padding: '9px 10px', font: 'inherit' };
const primaryButtonStyle: CSSProperties = { border: '1px solid rgba(99,102,241,.65)', borderRadius: 8, background: 'var(--grad-accent-soft)', color: 'var(--fg-0)', padding: '9px 14px', font: 'inherit', fontSize: 12.5, fontWeight: 650, cursor: 'pointer' };
const secondaryButtonStyle: CSSProperties = { ...primaryButtonStyle, background: 'transparent', borderColor: 'var(--hairline)', color: 'var(--fg-2)' };
const primarySmallButtonStyle: CSSProperties = { ...primaryButtonStyle, padding: '6px 10px', fontSize: 11.5 };
const iconActionStyle: CSSProperties = { width: 31, height: 31, display: 'grid', placeItems: 'center', padding: 0, borderRadius: 8, border: '1px solid var(--hairline)', background: 'var(--bg-2)', color: 'var(--fg-2)', cursor: 'pointer' };
const closeButtonStyle: CSSProperties = { ...iconActionStyle, flex: 'none', fontSize: 20, lineHeight: 1 };
const linkButtonStyle: CSSProperties = { border: 0, background: 'transparent', color: 'var(--accent)', cursor: 'pointer' };
const emptyStyle: CSSProperties = { padding: 18, textAlign: 'center', color: 'var(--fg-3)', border: '1px dashed var(--hairline)', borderRadius: 10, fontSize: 12.5 };
const errorStyle: CSSProperties = { padding: 10, color: '#fca5a5', background: 'rgba(239,68,68,.08)', border: '1px solid rgba(239,68,68,.3)', borderRadius: 8, fontSize: 12.5 };
const successStyle: CSSProperties = { padding: 10, color: '#86efac', background: 'rgba(34,197,94,.08)', border: '1px solid rgba(34,197,94,.3)', borderRadius: 8, fontSize: 12.5 };
const noticeStyle: CSSProperties = { padding: 10, color: '#fde68a', background: 'rgba(245,158,11,.08)', border: '1px solid rgba(245,158,11,.3)', borderRadius: 8, fontSize: 12.5 };
const mutedBadgeStyle: CSSProperties = { border: '1px solid var(--hairline)', borderRadius: 999, padding: '2px 7px', color: 'var(--fg-3)', fontSize: 10.5, whiteSpace: 'nowrap' };
const toolRowStyle: CSSProperties = { display: 'flex', alignItems: 'center', gap: 9, padding: '8px 0', borderTop: '1px solid color-mix(in srgb, var(--hairline) 65%, transparent)' };
const rowTitleStyle: CSSProperties = { display: 'block', color: 'var(--fg-1)', fontSize: 12.5 };
const rowSubtitleStyle: CSSProperties = { display: 'block', marginTop: 2, color: 'var(--fg-3)', fontSize: 11 };
const authFieldsetStyle: CSSProperties = { display: 'grid', gap: 7, minWidth: 0, margin: 0, padding: 0, border: 0 };
const authLegendStyle: CSSProperties = { marginBottom: 5, padding: 0, color: 'var(--fg-2)', fontSize: 12 };
const authGridStyle: CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 8 };
const authChoiceStyle: CSSProperties = { display: 'flex', alignItems: 'flex-start', gap: 8, padding: '10px', border: '1px solid var(--hairline)', borderRadius: 9, background: 'var(--bg-2)', cursor: 'pointer' };
const authChoiceSelectedStyle: CSSProperties = { borderColor: 'rgba(99,102,241,.65)', background: 'rgba(99,102,241,.1)', boxShadow: '0 0 0 1px rgba(99,102,241,.12)' };
const modalHeaderStyle: CSSProperties = { display: 'flex', alignItems: 'flex-start', gap: 12, padding: '18px 20px', borderBottom: '1px solid var(--hairline)' };
const modalBodyStyle: CSSProperties = { display: 'grid', gap: 14, padding: 20, overflowY: 'auto' };
const modalFooterStyle: CSSProperties = { display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '13px 20px', borderTop: '1px solid var(--hairline)', background: 'color-mix(in srgb, var(--bg-2) 50%, transparent)' };
const twoColumnStyle: CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 };
const advancedStyle: CSSProperties = { border: '1px solid var(--hairline)', borderRadius: 9, padding: '10px 12px', background: 'var(--bg-1)' };
const advancedSummaryStyle: CSSProperties = { color: 'var(--fg-2)', fontSize: 12, fontWeight: 600, cursor: 'pointer' };
const menuStyle: CSSProperties = { position: 'absolute', zIndex: 20, top: 36, right: 0, width: 175, display: 'grid', padding: 5, border: '1px solid var(--hairline)', borderRadius: 9, background: 'var(--panel-solid, var(--bg-1))', boxShadow: '0 12px 30px rgba(0,0,0,.25)' };
const menuButtonStyle: CSSProperties = { width: '100%', border: 0, borderRadius: 6, padding: '7px 9px', background: 'transparent', color: 'var(--fg-1)', textAlign: 'left', fontSize: 11.5, cursor: 'pointer' };
function riskStyle(risk: string): CSSProperties { return { ...mutedBadgeStyle, color: risk === 'read' ? '#86efac' : risk === 'destructive' ? '#fca5a5' : '#fde68a' }; }
