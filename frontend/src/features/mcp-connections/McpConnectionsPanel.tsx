import { useUiTranslation } from "../../lib/ui-locale";
import { useEffect, useMemo, useRef, useState, type CSSProperties, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '../../lib/auth-store';
import { Button } from '../../components/Button';
import { MoreHorizontal, Network } from 'lucide-react';
import { McpConnectionDialog } from './McpConnectionDialog';
import { useTeamStore } from '../../lib/team-store';
import { SourceAvatar } from '../admin/connectors/SourceAvatar';
import { toAdminError, type AdminApiError } from '../admin/shared/errors';
import { McpConnectionErrorDialog } from './McpConnectionErrorDialog';
import {
    mcpConnectionsApi,
    mcpOAuthNavigation,
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
    excludeLegacy?: boolean;
    oauthEnabled?: boolean;
}

export function McpConnectionsPanel(props: McpConnectionsPanelProps) {
    const tenant = useTeamStore((state) => state.currentTeam);
    return <TenantMcpConnectionsPanel key={`${tenant}:${props.scope}`} {...props} tenant={tenant} />;
}

function TenantMcpConnectionsPanel({
    scope,
    projects,
    projectsLoading = false,
    projectsError = false,
    createRequest = 0,
    embedded = false, excludeLegacy = false, oauthEnabled = true, tenant,
}: McpConnectionsPanelProps & { tenant: string | null }) {
    const { t: translateInterface } = useUiTranslation();
    const queryClient = useQueryClient();
    const queryKey = ['mcp-connections', tenant, scope] as const;
    const authProjects = useAuthStore((state) => state.projects);
    const projectOptions = useMemo<McpProjectOption[]>(
        () => projects ?? authProjects.map((project) => ({ project_key: project.project_key })),
        [authProjects, projects],
    );
    const [form, setForm] = useState<CreateMcpConnectionPayload>(blankForm);
    const [formOpen, setFormOpen] = useState(false);
    const [error, setError] = useState<AdminApiError | null>(null);
    const [errorDetails, setErrorDetails] = useState<AdminApiError | null>(null);
    const previousCreateRequest = useRef(createRequest);
    // Capture the callback result once. The URL is cleaned immediately below,
    // while the connections query can still trigger additional renders.
    const [oauthResult] = useState(() =>
        typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('mcp'),
    );
    const query = useQuery({
        queryKey,
        queryFn: () => mcpConnectionsApi.list(scope, tenant),
        retry: false,
        refetchOnWindowFocus: false,
    });
    const refresh = async () => queryClient.invalidateQueries({ queryKey });
    const create = useMutation({
        mutationFn: (payload: CreateMcpConnectionPayload) => mcpConnectionsApi.create(scope, payload, tenant),
        gcTime: 0,
        onSuccess: async (result) => {
            if (result.next_action?.type === 'oauth_redirect') {
                if (useTeamStore.getState().currentTeam === tenant) mcpOAuthNavigation.redirect(result.next_action.authorization_url);
                return;
            }
            closeForm();
            setError(null);
            setErrorDetails(null);
            await refresh();
        },
        onError: (cause) => setError(toAdminError(cause)),
    });
    const action = useMutation({
        mutationFn: async (request: { kind: 'discover' | 'disconnect' | 'remove' | 'sync-resources'; id: string }) => {
            if (request.kind === 'discover') return mcpConnectionsApi.discover(scope, request.id, tenant);
            if (request.kind === 'disconnect') return mcpConnectionsApi.disconnect(scope, request.id, tenant);
            if (request.kind === 'sync-resources') return mcpConnectionsApi.syncResources(request.id, tenant);
            return mcpConnectionsApi.remove(scope, request.id, tenant);
        },
        onSuccess: refresh,
        onError: (cause) => setError(toAdminError(cause)),
    });
    const toolMutation = useMutation({
        mutationFn: (request: { connectionId: string; toolId: number; enabled: boolean }) =>
            mcpConnectionsApi.setTool(scope, request.connectionId, request.toolId, request.enabled, tenant),
        onSuccess: refresh,
        onError: (cause) => setError(toAdminError(cause)),
    });
    const resourceMutation = useMutation({
        mutationFn: (request: { connectionId: string; resourceId: number; enabled: boolean }) =>
            mcpConnectionsApi.setResource(request.connectionId, request.resourceId, request.enabled, tenant),
        onSuccess: refresh,
        onError: (cause) => setError(toAdminError(cause)),
    });
    const oauth = useMutation({
        mutationFn: (connectionId: string) => mcpConnectionsApi.beginOAuth(scope, connectionId, tenant),
        onSuccess: (url) => { if (useTeamStore.getState().currentTeam === tenant) mcpOAuthNavigation.redirect(url); },
        onError: (cause) => setError(toAdminError(cause)),
    });

    useEffect(() => {
        if (!oauthEnabled) setForm((current) => current.auth_method === 'oauth' ? { ...current, auth_method: 'bearer' } : current);
    }, [oauthEnabled]);

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
        setErrorDetails(null);
        setFormOpen(true);
    }, [createRequest]);

    const connections = useMemo(() => (query.data ?? []).filter((connection) => !excludeLegacy
        || (!connection.server.legacy_reference && connection.server.transport !== 'stdio_imported')), [query.data, excludeLegacy]);
    const state = query.isLoading ? 'loading' : query.isError ? 'error' : 'ready';
    const title = scope === 'shared' ? 'MCP connections' : 'Connected Apps';
    const subtitle = scope === 'shared'
        ? 'Live tools and resources exposed by remote MCP servers.'
        : 'Connect your own MCP apps. Personal credentials and tools are visible only to you.';

    function closeForm() {
        create.reset();
        setForm(blankForm);
        setFormOpen(false);
        setError(null);
        setErrorDetails(null);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        setError(null);
        setErrorDetails(null);
        create.mutate({
            ...form,
            name: form.name.trim(),
            endpoint: form.endpoint.trim(),
            label: form.label?.trim() || form.name.trim(),
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
                        <h2 style={embedded ? groupHeadingStyle : titleStyle}>{translateInterface(title)}</h2>
                        <span data-testid="mcp-connections-count" style={countBadgeStyle}>{connections.length}</span>
                    </div>
                    {!embedded && <p style={subtitleStyle}>{translateInterface(subtitle)}</p>}
                </div>
                {!embedded && (
                    <Button variant="primary" onClick={() => setFormOpen(true)} data-testid="mcp-connection-add">
                        {translateInterface("Add MCP connection")}
                    </Button>
                )}
            </div>

            {oauthResult && (
                <div
                    role={oauthResult === 'connected' ? 'status' : 'alert'}
                    data-testid="mcp-oauth-result"
                    style={oauthResult === 'connected' ? successStyle : oauthResult === 'oauth_denied' ? noticeStyle : errorStyle}
                >
                    {translateInterface(oauthMessage(oauthResult))}
                </div>
            )}

            {error && !formOpen && <McpErrorAlert error={error} onShowDetails={() => setErrorDetails(error)} />}

            {query.isLoading && <div role="status" style={emptyStyle}>Loading MCP connections…</div>}
            {query.isError && (
                <div role="alert" style={emptyStyle}>
                    MCP connections are unavailable. The feature may still be disabled for this environment.{' '}
                    <Button variant="quiet" size="sm" onClick={() => void query.refetch()} data-testid="mcp-connections-retry">{translateInterface("Retry")}</Button>
                </div>
            )}
            {!query.isLoading && !query.isError && connections.length === 0 && (
                <div role="status" className="mcp-remote-empty" style={emptyStyle}><Network size={30} aria-hidden /><h3>{translateInterface('No remote servers connected')}</h3><p>{translateInterface('Connect a server with OAuth, a Bearer token, or public access. Its tools and resources will appear here.')}</p></div>
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

            {formOpen && <McpConnectionDialog scope={scope} form={form} onChange={setForm} onSubmit={submit} onClose={closeForm}
                busy={create.isPending} projects={projectOptions} projectsLoading={projectsLoading} projectsError={projectsError} oauthEnabled={oauthEnabled}
                error={error ? <McpErrorAlert error={error} onShowDetails={() => setErrorDetails(error)} /> : undefined} />}

            {errorDetails && (
                <McpConnectionErrorDialog error={errorDetails} onClose={() => setErrorDetails(null)} />
            )}
        </section>
    );
}

function McpErrorAlert({ error, onShowDetails }: { error: AdminApiError; onShowDetails: () => void }) {
    return (
        <div role="alert" style={{ ...errorStyle, display: 'flex', alignItems: 'center', gap: 10 }}>
            <span style={{ flex: 1, minWidth: 0 }}>{error.message}</span>
            <Button
                variant="quiet"
                size="sm"
                iconOnly
                aria-label="View connection error details"
                title="View error details"
                data-testid="mcp-connection-error-info"
                onClick={onShowDetails}
                style={{ flex: 'none', color: 'var(--err)' }}
            >
                <InfoIcon />
            </Button>
        </div>
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
    const { t } = useUiTranslation();
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
                <SourceAvatar connectorKey="mcp" displayName="MCP" />
                <div style={{ flex: 1, minWidth: 150 }}>
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
                            {t('Connect OAuth')}
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
                            <MoreHorizontal size={17} aria-hidden />
                        </Button>
                        {menuOpen && (
                            <div role="menu" style={menuStyle}>
                                {oauthRequired && connection.status === 'active' && (
                                    <MenuButton disabled={busy} onClick={() => { setMenuOpen(false); onOAuth(); }}>{t('Reconnect OAuth')}</MenuButton>
                                )}
                                <MenuButton disabled={busy} onClick={() => { setMenuOpen(false); onAction('discover'); }}>{t('Refresh catalog')}</MenuButton>
                                {connection.mode === 'shared' && connection.resources.some((resource) => resource.enabled) && (
                                    <MenuButton disabled={busy || connection.connector_installation_id === null} onClick={() => { setMenuOpen(false); onAction('sync-resources'); }}>{t('Sync resources')}</MenuButton>
                                )}
                                <MenuButton disabled={busy} onClick={() => { setMenuOpen(false); onAction('disconnect'); }}>{t('Disconnect')}</MenuButton>
                                <MenuButton danger disabled={busy} onClick={() => { setMenuOpen(false); onAction('remove'); }}>{t('Remove connection')}</MenuButton>
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
                                {resource.ingest_error_json && <span style={{ ...mutedBadgeStyle, color: 'var(--err)' }}>error</span>}
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
    const { t } = useUiTranslation();
    return (
        <section style={capabilityStyle}>
            <div style={capabilityHeadingStyle}>
                <span>{t(title)}</span>
                <span style={countBadgeStyle}>{count}</span>
            </div>
            {count === 0 ? <div style={emptyCapabilityStyle}>{t(empty)}</div> : children}
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

function oauthMessage(status: string): string {
    if (status === 'connected') return 'OAuth connection completed. Tools and resources are ready.';
    if (status === 'discovery_failed') return 'Sign-in completed, but the MCP catalog could not be loaded. You can retry discovery from the connection.';
    if (status === 'oauth_denied') return 'OAuth sign-in was cancelled. No credentials were saved.';
    return 'OAuth sign-in could not be completed. Please try again.';
}

function StatusBadge({ status }: { status: string }) {
    const { t } = useUiTranslation();
    const labels: Record<string, string> = { active: 'Active', disabled: 'Disabled', pending: 'Pending', errored: 'Connection error', reauthorization_required: 'Reauthorization required' };
    const color = status === 'active' ? 'var(--accent-a)' : status === 'errored' || status === 'reauthorization_required' ? 'var(--err)' : 'var(--fg-2)';
    return <span style={{ ...mutedBadgeStyle, color }}>{t(labels[status] ?? status.replaceAll('_', ' '))}</span>;
}

function hostOf(endpoint: string): string {
    try {
        return new URL(endpoint).host || endpoint;
    } catch {
        return endpoint;
    }
}

function InfoIcon() {
    return (
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5" />
            <path d="M12 8h.01" />
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

const panelStyle: CSSProperties = { display: 'grid', gap: 14, border: '1px solid var(--hairline)', borderRadius: 'var(--design-card-radius, 14px)', background: 'var(--bg-1)', padding: 'var(--design-card-padding, 18px)' };
const embeddedPanelStyle: CSSProperties = { display: 'grid', gap: 10 };
const titleStyle: CSSProperties = { margin: 0, color: 'var(--fg-0)', fontSize: 18 };
const subtitleStyle: CSSProperties = { margin: '6px 0 0', color: 'var(--fg-3)', fontSize: 13, lineHeight: 1.5 };
const groupHeadingStyle: CSSProperties = { margin: 0, fontSize: 12, fontWeight: 650, textTransform: 'uppercase', letterSpacing: '.055em', color: 'var(--fg-3)' };
const cardStyle: CSSProperties = { position: 'relative', border: '1px solid var(--hairline)', borderRadius: 'var(--design-card-radius, 12px)', background: 'var(--bg-1)', boxShadow: '0 1px 0 rgba(255,255,255,.02)' };
const cardHeaderStyle: CSSProperties = { display: 'flex', alignItems: 'center', gap: 12, padding: 'var(--design-card-padding, 13px)', flexWrap: 'wrap' };
const cardActionsStyle: CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, marginLeft: 'auto' };
const endpointStyle: CSSProperties = { marginTop: 4, color: 'var(--fg-3)', fontSize: 11.5, fontFamily: 'var(--font-mono)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' };
const summaryBadgeStyle: CSSProperties = { borderRadius: 999, padding: '3px 8px', background: 'var(--bg-2)', color: 'var(--fg-2)', fontSize: 10.5, whiteSpace: 'nowrap' };
const detailsStyle: CSSProperties = { display: 'grid', gap: 12, padding: '0 var(--design-card-padding, 13px) var(--design-card-padding, 13px)', borderTop: '1px solid var(--hairline)' };
const metadataBarStyle: CSSProperties = { display: 'flex', gap: 14, padding: '10px 0 0', color: 'var(--fg-3)', fontSize: 10.5, fontFamily: 'var(--font-mono)', overflow: 'hidden' };
const capabilityStyle: CSSProperties = { border: '1px solid var(--hairline)', borderRadius: 9, background: 'var(--bg-0)', padding: '0 10px' };
const capabilityHeadingStyle: CSSProperties = { display: 'flex', alignItems: 'center', gap: 7, minHeight: 36, color: 'var(--fg-1)', fontSize: 12, fontWeight: 650 };
const emptyCapabilityStyle: CSSProperties = { padding: '0 0 10px', color: 'var(--fg-3)', fontSize: 11.5 };
const countBadgeStyle: CSSProperties = { borderRadius: 999, padding: '1px 7px', color: 'var(--fg-2)', background: 'var(--bg-2)', fontSize: 10.5, fontWeight: 600 };
const emptyStyle: CSSProperties = { padding: 18, textAlign: 'center', color: 'var(--fg-3)', border: '1px dashed var(--hairline)', borderRadius: 10, fontSize: 12.5 };
const errorStyle: CSSProperties = { padding: 10, color: 'var(--err)', background: 'rgba(239,68,68,.08)', border: '1px solid rgba(239,68,68,.3)', borderRadius: 8, fontSize: 12.5 };
const successStyle: CSSProperties = { padding: 10, color: 'var(--accent-a)', background: 'rgba(34,197,94,.08)', border: '1px solid rgba(34,197,94,.3)', borderRadius: 8, fontSize: 12.5 };
const noticeStyle: CSSProperties = { padding: 10, color: 'var(--fg-2)', background: 'rgba(245,158,11,.08)', border: '1px solid rgba(245,158,11,.3)', borderRadius: 8, fontSize: 12.5 };
const mutedBadgeStyle: CSSProperties = { border: '1px solid var(--hairline)', borderRadius: 999, padding: '2px 7px', color: 'var(--fg-3)', fontSize: 10.5, whiteSpace: 'nowrap' };
const toolRowStyle: CSSProperties = { display: 'flex', alignItems: 'center', gap: 9, padding: '8px 0', borderTop: '1px solid color-mix(in srgb, var(--hairline) 65%, transparent)' };
const rowTitleStyle: CSSProperties = { display: 'block', color: 'var(--fg-1)', fontSize: 12.5 };
const rowSubtitleStyle: CSSProperties = { display: 'block', marginTop: 2, color: 'var(--fg-3)', fontSize: 11 };
const menuStyle: CSSProperties = { position: 'absolute', zIndex: 20, top: 36, right: 0, width: 175, display: 'grid', padding: 5, border: '1px solid var(--hairline)', borderRadius: 9, background: 'var(--panel-solid, var(--bg-1))', boxShadow: '0 12px 30px rgba(0,0,0,.25)' };
function riskStyle(risk: string): CSSProperties { return { ...mutedBadgeStyle, color: risk === 'read' ? 'var(--accent-a)' : risk === 'destructive' ? 'var(--err)' : 'var(--fg-2)' }; }
