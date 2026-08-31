import { useEffect, useMemo, useRef, useState, type CSSProperties, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '../../lib/auth-store';
import { Button } from '../../components/Button';
import { modalBackdropStyle, modalPanelStyle } from '../admin/api-connectors/styles';
import { toAdminError } from '../admin/shared/errors';
import {
    mcpConnectionsApi,
    type CreateMcpConnectionPayload,
    type McpAuthenticationMethod,
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
                    <Button variant="primary" onClick={() => setFormOpen(true)} data-testid="mcp-connection-add">
                        Add MCP connection
                    </Button>
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
                    <Button variant="quiet" size="sm" onClick={() => void query.refetch()} data-testid="mcp-connections-retry">Retry</Button>
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
                            <Button
                                variant="quiet"
                                size="sm"
                                iconOnly
                                aria-label="Close MCP connection form"
                                data-testid="mcp-connection-form-close"
                                disabled={create.isPending}
                                onClick={closeForm}
                                style={{ flex: 'none' }}
                            >
                                ×
                            </Button>
                        </div>

                        <div style={modalBodyStyle}>
                            {error && <div role="alert" style={errorStyle}>{error}</div>}

                            <div style={twoColumnStyle}>
                                <Field label="Name">
                                    <input required autoFocus data-testid="mcp-connection-name" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} style={inputStyle} />
                                </Field>
                                <Field label="Label">
                                    <input data-testid="mcp-connection-label" value={form.label ?? ''} onChange={(event) => setForm({ ...form, label: event.target.value })} style={inputStyle} />
                                </Field>
                            </div>

                            <Field label="MCP endpoint">
                                <input required data-testid="mcp-connection-endpoint" type="url" placeholder="https://mcp.example.com/rpc" value={form.endpoint} onChange={(event) => setForm({ ...form, endpoint: event.target.value })} style={inputStyle} />
                            </Field>

                            <Field label="Project (optional)" hint={projectsError ? 'Projects could not be loaded.' : 'Controls where shared resources are indexed.'}>
                                <select
                                    data-testid="mcp-connection-project"
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
                                        kind="oauth"
                                        checked={form.auth_method === 'oauth'}
                                        label="OAuth"
                                        description="Sign in securely"
                                        onChange={() => setForm({ ...form, auth_method: 'oauth', bearer: '' })}
                                    />
                                    <AuthChoice
                                        kind="bearer"
                                        checked={form.auth_method === 'bearer'}
                                        label="Bearer token"
                                        description="Use an existing token"
                                        onChange={() => setForm({ ...form, auth_method: 'bearer' })}
                                    />
                                    <AuthChoice
                                        kind="none"
                                        checked={form.auth_method === 'none'}
                                        label="No authentication"
                                        description="Public endpoints only"
                                        onChange={() => setForm({ ...form, auth_method: 'none', bearer: '' })}
                                    />
                                </div>
                            </fieldset>

                            {form.auth_method === 'bearer' && (
                                <Field label="Bearer token">
                                    <input required data-testid="mcp-connection-bearer" type="password" autoComplete="new-password" value={form.bearer ?? ''} onChange={(event) => setForm({ ...form, bearer: event.target.value })} style={inputStyle} />
                                </Field>
                            )}

                            <details style={advancedStyle}>
                                <summary style={advancedSummaryStyle} className="focus-ring">
                                    <span style={advancedSummaryLabelStyle}>
                                        <AdvancedSettingsIcon />
                                        Advanced settings
                                    </span>
                                    <span style={advancedSummaryValueStyle}>{transportLabel(form.transport)}</span>
                                </summary>
                                <fieldset style={transportFieldsetStyle}>
                                    <legend style={transportLegendStyle}>Transport</legend>
                                    <div style={transportGridStyle}>
                                        <TransportChoice
                                            kind="auto"
                                            value="auto"
                                            checked={form.transport === 'auto'}
                                            label="Auto"
                                            description="Choose the best protocol"
                                            badge="Recommended"
                                            onChange={() => setForm({ ...form, transport: 'auto' })}
                                        />
                                        <TransportChoice
                                            kind="streamable_http"
                                            value="streamable_http"
                                            checked={form.transport === 'streamable_http'}
                                            label="Streamable HTTP"
                                            description="Modern bidirectional HTTP"
                                            onChange={() => setForm({ ...form, transport: 'streamable_http' })}
                                        />
                                        <TransportChoice
                                            kind="legacy_sse"
                                            value="legacy_sse"
                                            checked={form.transport === 'legacy_sse'}
                                            label="Legacy SSE"
                                            description="Older event-stream servers"
                                            onChange={() => setForm({ ...form, transport: 'legacy_sse' })}
                                        />
                                    </div>
                                </fieldset>
                            </details>
                        </div>

                        <div style={modalFooterStyle}>
                            <Button variant="secondary" disabled={create.isPending} onClick={closeForm} data-testid="mcp-connection-cancel">Cancel</Button>
                            <Button type="submit" variant="primary" busy={create.isPending} data-testid="mcp-connection-submit">
                                {form.auth_method === 'oauth' ? 'Continue with OAuth' : 'Connect and discover'}
                            </Button>
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
                        <Button variant="primary" size="sm" busy={busy} onClick={onOAuth} data-testid={`mcp-connection-${connection.public_id}-oauth`}>
                            Connect OAuth
                        </Button>
                    )}
                    <Button
                        variant="quiet"
                        size="sm"
                        iconOnly
                        aria-expanded={expanded}
                        aria-label={expanded ? `Collapse ${connection.label}` : `Expand ${connection.label}`}
                        data-testid={`mcp-connection-${connection.public_id}-expand`}
                        onClick={() => setExpanded((open) => !open)}
                    >
                        <ChevronIcon expanded={expanded} />
                    </Button>
                    <div style={{ position: 'relative' }}>
                        <Button
                            variant="quiet"
                            size="sm"
                            iconOnly
                            aria-label={`More actions for ${connection.label}`}
                            aria-expanded={menuOpen}
                            data-testid={`mcp-connection-${connection.public_id}-menu`}
                            onClick={() => setMenuOpen((open) => !open)}
                        >
                            <span aria-hidden="true" style={{ fontSize: 18, lineHeight: 1 }}>⋯</span>
                        </Button>
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
    return <Button variant={danger ? 'danger' : 'quiet'} size="sm" role="menuitem" style={{ width: '100%', justifyContent: 'flex-start' }} {...props}>{children}</Button>;
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

function AuthChoice({
    kind,
    checked,
    label,
    description,
    onChange,
}: {
    kind: McpAuthenticationMethod;
    checked: boolean;
    label: string;
    description: string;
    onChange: () => void;
}) {
    return (
        <label style={{ ...authChoiceStyle, ...(checked ? authChoiceSelectedStyle : {}) }}>
            <span aria-hidden="true" style={{ ...choiceIconStyle, ...(checked ? choiceIconSelectedStyle : {}) }}>
                <AuthenticationIcon kind={kind} />
            </span>
            <span style={choiceCopyStyle}>
                <strong style={choiceLabelStyle}>{label}</strong>
                <span style={choiceDescriptionStyle}>{description}</span>
            </span>
            <input
                data-testid={`mcp-auth-${kind}`}
                type="radio"
                name="mcp-auth-method"
                checked={checked}
                onChange={onChange}
                className="focus-ring"
                style={{ ...choiceRadioStyle, ...(checked ? choiceRadioCheckedStyle : {}) }}
            />
        </label>
    );
}

type McpTransport = CreateMcpConnectionPayload['transport'];

function TransportChoice({
    kind,
    value,
    checked,
    label,
    description,
    badge,
    onChange,
}: {
    kind: McpTransport;
    value: McpTransport;
    checked: boolean;
    label: string;
    description: string;
    badge?: string;
    onChange: () => void;
}) {
    return (
        <label style={{ ...transportChoiceStyle, ...(checked ? transportChoiceSelectedStyle : {}) }}>
            <span aria-hidden="true" style={{ ...transportIconStyle, ...(checked ? transportIconSelectedStyle : {}) }}>
                <TransportIcon kind={kind} />
            </span>
            <span style={choiceCopyStyle}>
                <span style={transportLabelRowStyle}>
                    <strong style={choiceLabelStyle}>{label}</strong>
                    {badge && <span style={recommendedBadgeStyle}>{badge}</span>}
                </span>
                <span style={choiceDescriptionStyle}>{description}</span>
            </span>
            <input
                data-testid={`mcp-transport-${value}`}
                type="radio"
                name="mcp-transport"
                value={value}
                checked={checked}
                onChange={onChange}
                className="focus-ring"
                style={{ ...choiceRadioStyle, ...(checked ? choiceRadioCheckedStyle : {}) }}
            />
        </label>
    );
}

function transportLabel(transport: McpTransport): string {
    if (transport === 'streamable_http') return 'Streamable HTTP';
    if (transport === 'legacy_sse') return 'Legacy SSE';
    return 'Auto';
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

function AuthenticationIcon({ kind }: { kind: McpAuthenticationMethod }) {
    if (kind === 'oauth') {
        return (
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 3 5.5 5.7v5.4c0 4.1 2.6 7.8 6.5 9.9 3.9-2.1 6.5-5.8 6.5-9.9V5.7L12 3Z" />
                <path d="m9.2 12 1.9 1.9 3.8-4.1" />
            </svg>
        );
    }

    if (kind === 'bearer') {
        return (
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <circle cx="8.2" cy="12" r="4.2" />
                <path d="M12.4 12H21m-3 0v3m-3-3v2" />
            </svg>
        );
    }

    return (
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
        </svg>
    );
}

function TransportIcon({ kind }: { kind: McpTransport }) {
    if (kind === 'auto') {
        return (
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <path d="m4 20 8.8-8.8M14.4 9.6l-2-2 2.9-2.9 2 2-2.9 2.9Z" />
                <path d="M18.5 3.5v3M20 5h-3M6.5 5v4M8.5 7h-4M18 15v4M20 17h-4" />
            </svg>
        );
    }

    if (kind === 'streamable_http') {
        return (
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <path d="M4 8h15m-3-3 3 3-3 3M20 16H5m3 3-3-3 3-3" />
            </svg>
        );
    }

    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
            <path d="M8.5 8.5a5 5 0 0 0 0 7M15.5 8.5a5 5 0 0 1 0 7M5.5 5.5a9.2 9.2 0 0 0 0 13M18.5 5.5a9.2 9.2 0 0 1 0 13" />
        </svg>
    );
}

function AdvancedSettingsIcon() {
    return (
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
            <path d="M4 7h9M17 7h3M4 17h3M11 17h9" />
            <circle cx="15" cy="7" r="2" />
            <circle cx="9" cy="17" r="2" />
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
const authGridStyle: CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 8 };
const authChoiceStyle: CSSProperties = { minWidth: 0, display: 'grid', gridTemplateColumns: '36px minmax(0, 1fr) 18px', alignItems: 'center', gap: 10, minHeight: 62, boxSizing: 'border-box', padding: '9px 11px', border: '1px solid var(--hairline)', borderRadius: 11, background: 'color-mix(in srgb, var(--bg-2) 86%, transparent)', cursor: 'pointer', transition: 'border-color .16s ease, background .16s ease, box-shadow .16s ease, transform .16s ease' };
const authChoiceSelectedStyle: CSSProperties = { borderColor: 'color-mix(in srgb, var(--accent-a) 62%, var(--hairline))', background: 'linear-gradient(135deg, color-mix(in srgb, var(--accent-a) 13%, var(--bg-2)), color-mix(in srgb, var(--accent-b) 5%, var(--bg-2)))', boxShadow: '0 8px 24px -20px rgba(var(--accent-glow), .95), inset 0 1px rgba(255,255,255,.025)' };
const choiceIconStyle: CSSProperties = { width: 34, height: 34, display: 'grid', placeItems: 'center', border: '1px solid var(--hairline)', borderRadius: 9, color: 'var(--fg-3)', background: 'color-mix(in srgb, var(--bg-0) 75%, transparent)', transition: 'color .16s ease, border-color .16s ease, background .16s ease' };
const choiceIconSelectedStyle: CSSProperties = { color: 'var(--accent-b)', borderColor: 'color-mix(in srgb, var(--accent-b) 28%, var(--hairline))', background: 'color-mix(in srgb, var(--accent-b) 9%, var(--bg-0))' };
const choiceCopyStyle: CSSProperties = { display: 'grid', alignContent: 'center', minWidth: 0, gap: 3 };
const choiceLabelStyle: CSSProperties = { display: 'block', color: 'var(--fg-1)', fontSize: 12.5, lineHeight: 1.25, fontWeight: 650 };
const choiceDescriptionStyle: CSSProperties = { display: 'block', color: 'var(--fg-3)', fontSize: 10.75, lineHeight: 1.35 };
const choiceRadioStyle: CSSProperties = { WebkitAppearance: 'none', appearance: 'none', width: 17, height: 17, margin: 0, border: '1px solid color-mix(in srgb, var(--fg-3) 70%, transparent)', borderRadius: '50%', background: 'transparent', cursor: 'pointer', transition: 'border-color .16s ease, background .16s ease, box-shadow .16s ease' };
const choiceRadioCheckedStyle: CSSProperties = { borderColor: 'var(--accent-a)', background: 'radial-gradient(circle, white 0 2.5px, var(--accent-a) 3px 100%)', boxShadow: '0 0 0 3px color-mix(in srgb, var(--accent-a) 13%, transparent)' };
const modalHeaderStyle: CSSProperties = { display: 'flex', alignItems: 'flex-start', gap: 12, padding: '18px 20px', borderBottom: '1px solid var(--hairline)' };
const modalBodyStyle: CSSProperties = { display: 'grid', gap: 14, padding: 20, overflowY: 'auto' };
const modalFooterStyle: CSSProperties = { display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '13px 20px', borderTop: '1px solid var(--hairline)', background: 'color-mix(in srgb, var(--bg-2) 50%, transparent)' };
const twoColumnStyle: CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 };
const advancedStyle: CSSProperties = { border: '1px solid var(--hairline)', borderRadius: 11, padding: '0 12px', background: 'color-mix(in srgb, var(--bg-1) 82%, var(--bg-0))' };
const advancedSummaryStyle: CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, minHeight: 42, listStyle: 'none', color: 'var(--fg-2)', fontSize: 12, fontWeight: 600, cursor: 'pointer', borderRadius: 8 };
const advancedSummaryLabelStyle: CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 7 };
const advancedSummaryValueStyle: CSSProperties = { border: '1px solid var(--hairline)', borderRadius: 999, padding: '3px 8px', color: 'var(--fg-3)', background: 'var(--bg-2)', fontSize: 10.5, fontWeight: 550 };
const transportFieldsetStyle: CSSProperties = { display: 'grid', gap: 8, minWidth: 0, margin: 0, padding: '2px 0 12px', border: 0, borderTop: '1px solid color-mix(in srgb, var(--hairline) 75%, transparent)' };
const transportLegendStyle: CSSProperties = { padding: '11px 0 0', color: 'var(--fg-3)', fontSize: 10.5, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.055em' };
const transportGridStyle: CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 8 };
const transportChoiceStyle: CSSProperties = { minWidth: 0, display: 'grid', gridTemplateColumns: '31px minmax(0, 1fr) 17px', alignItems: 'center', gap: 9, minHeight: 56, boxSizing: 'border-box', padding: '8px 10px', border: '1px solid var(--hairline)', borderRadius: 10, color: 'var(--fg-2)', background: 'var(--bg-2)', cursor: 'pointer', transition: 'border-color .16s ease, background .16s ease, box-shadow .16s ease' };
const transportChoiceSelectedStyle: CSSProperties = { borderColor: 'color-mix(in srgb, var(--accent-b) 48%, var(--hairline))', background: 'linear-gradient(135deg, color-mix(in srgb, var(--accent-b) 9%, var(--bg-2)), color-mix(in srgb, var(--accent-a) 5%, var(--bg-2)))', boxShadow: 'inset 0 1px rgba(255,255,255,.025)' };
const transportIconStyle: CSSProperties = { width: 29, height: 29, display: 'grid', placeItems: 'center', borderRadius: 8, color: 'var(--fg-3)', background: 'color-mix(in srgb, var(--bg-0) 76%, transparent)' };
const transportIconSelectedStyle: CSSProperties = { color: 'var(--accent-b)', background: 'color-mix(in srgb, var(--accent-b) 9%, var(--bg-0))' };
const transportLabelRowStyle: CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, minWidth: 0, flexWrap: 'wrap' };
const recommendedBadgeStyle: CSSProperties = { borderRadius: 999, padding: '1px 5px', color: 'var(--accent-b)', background: 'color-mix(in srgb, var(--accent-b) 9%, transparent)', fontSize: 8.5, fontWeight: 700, letterSpacing: '.02em' };
const menuStyle: CSSProperties = { position: 'absolute', zIndex: 20, top: 36, right: 0, width: 175, display: 'grid', padding: 5, border: '1px solid var(--hairline)', borderRadius: 9, background: 'var(--panel-solid, var(--bg-1))', boxShadow: '0 12px 30px rgba(0,0,0,.25)' };
function riskStyle(risk: string): CSSProperties { return { ...mutedBadgeStyle, color: risk === 'read' ? '#86efac' : risk === 'destructive' ? '#fca5a5' : '#fde68a' }; }
