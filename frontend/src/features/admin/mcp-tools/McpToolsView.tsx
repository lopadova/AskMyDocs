import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Building2, ChevronDown, Info, Network, Pause, Plus, Terminal, Trash2 } from 'lucide-react';
import { Button } from '../../../components/Button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../../../components/ui/dialog';
import { useUiTranslation } from '../../../lib/ui-locale';
import { useTeamStore } from '../../../lib/team-store';
import { parseLaravelError } from '../../../lib/laravel-errors';
import { McpConnectionsPanel } from '../../mcp-connections/McpConnectionsPanel';
import { AdminShell } from '../shell/AdminShell';
import { adminProjectsApi } from '../projects/admin-projects.api';
import { useDeleteMcpServer, useDisableMcpServer, useHandshakeMcpServer, useMcpCapabilities, useMcpServers, useUpdateMcpServerTools } from './mcp-tools-hooks';
import { HandshakeStatus } from './HandshakeStatus';
import { ToolMatrix } from './ToolMatrix';
import { RegisterServerDialog } from './RegisterServerDialog';
import { McpToolCallAuditView } from './McpToolCallAuditView';
import type { McpServerEntry } from './mcp-tools.api';
import './mcp-tools.css';

export function McpToolsView() {
    const tenant = useTeamStore((state) => state.currentTeam);
    return <TenantMcpTools key={tenant ?? 'none'} />;
}
function TenantMcpTools() {
    const { t } = useUiTranslation();
    const tenant = useTeamStore((state) => state.currentTeam);
    const team = useTeamStore((state) => state.teams.find((item) => item.tenant_id === state.currentTeam));
    const [tab, setTab] = useState<'servers' | 'audit'>('servers');
    const [createRequest, setCreateRequest] = useState(0);
    const [showRegister, setShowRegister] = useState(false);
    const [showLegacy, setShowLegacy] = useState(false);
    const [highlightId, setHighlightId] = useState<number | null>(null);
    const capabilities = useMcpCapabilities();
    const projects = useQuery({ queryKey: ['mcp-admin-projects', tenant], queryFn: () => adminProjectsApi.list(tenant), retry: false });
    return <AdminShell section="mcp-tools"><section className="mcp-tools-page" data-testid="admin-mcp-tools" data-state={capabilities.isLoading ? 'loading' : 'ready'}>
        <header className="mcp-tools-heading"><div><span className="mcp-tools-eyebrow"><Network size={14} aria-hidden />{t('External capabilities')}</span><h1 id="admin-mcp-tools-title" data-testid="admin-mcp-tools-title">{t('MCP tools')}</h1><p>{t('Connect external services and choose the tools available to your agent.')}</p></div>
            {tab === 'servers' && <div className="mcp-tools-actions"><Button variant="quiet" size="md" leadingIcon={<Terminal size={15} aria-hidden />} onClick={() => setShowRegister(true)} data-testid="admin-mcp-tools-manual">{t('Manual registration')}</Button><Button variant="primary" size="md" leadingIcon={<Plus size={15} aria-hidden />} disabled={!capabilities.data?.connector_enabled} data-testid="admin-mcp-tools-register" onClick={() => setCreateRequest((request) => request + 1)}>{t('Connect server')}</Button></div>}
        </header>
        <div className="mcp-tools-context"><Building2 size={21} aria-hidden /><div><small>{t('Organization')}</small><strong>{team?.name ?? t('Current organization')}</strong></div><span><Info size={14} aria-hidden />{t('Shared connections for this organization')}</span></div>
        <nav className="mcp-tools-tabs" aria-label={t('MCP tools sections')}><div className="ui-button-group">{(['servers', 'audit'] as const).map((id) => <Button key={id} variant="quiet" size="sm" aria-pressed={tab === id} data-testid={`admin-mcp-tools-tab-${id}`} onClick={() => setTab(id)}>{t(id === 'servers' ? 'Servers' : 'Audit log')}</Button>)}</div></nav>
        {tab === 'audit' ? <McpToolCallAuditView /> : <>
            {capabilities.isLoading && <div className="mcp-tools-state" role="status">{t('Loading MCP configuration…')}</div>}
            {capabilities.isError && <div className="mcp-tools-alert" role="alert">{t('Could not load MCP configuration.')} <Button variant="secondary" size="sm" onClick={() => void capabilities.refetch()}>{t('Retry')}</Button></div>}
            {capabilities.data && !capabilities.data.connector_enabled && <div className="mcp-tools-alert" role="status">{t('Remote connections are disabled for this installation. Enable the MCP connector to use OAuth and remote discovery.')}</div>}
            {capabilities.data?.connector_enabled && <>
                {capabilities.data.runtime_mode !== 'active' && <div className="mcp-tools-alert" role="status">{t('Connections can be configured, but their tools will be used only when the MCP runtime is active in Configuration.')}</div>}
                <section className="mcp-tools-remote"><div className="mcp-tools-section-heading"><div><h2>{t('Remote servers')}</h2><p>{t('OAuth, Bearer token, or public access. Discovery retrieves the server’s actual capabilities.')}</p></div><span className="mcp-tools-auth-badge">{capabilities.data.oauth_enabled ? t('OAuth available') : t('OAuth disabled')}</span></div>
                    <McpConnectionsPanel scope="shared" embedded excludeLegacy oauthEnabled={capabilities.data.oauth_enabled} createRequest={createRequest} projects={projects.data} projectsLoading={projects.isLoading} projectsError={projects.isError} />
                </section>
            </>}
            <details className="mcp-tools-legacy" open={showLegacy} onToggle={(event) => setShowLegacy(event.currentTarget.open)}><summary><Terminal size={18} aria-hidden /><span><strong>{t('Manual and local servers')}</strong><small>{t('Existing registrations, stdio commands, and custom authentication.')}</small></span><ChevronDown size={16} aria-hidden /></summary><ServersTab highlightId={highlightId} /></details>
        </>}
        {showRegister && <RegisterServerDialog open onClose={() => setShowRegister(false)} onCreated={(id) => { setHighlightId(id); setShowLegacy(true); setTab('servers'); }} />}
    </section></AdminShell>;
}

function ServersTab({ highlightId }: { highlightId: number | null }) {
    const { t } = useUiTranslation();
    const servers = useMcpServers();
    const handshake = useHandshakeMcpServer();
    const updateTools = useUpdateMcpServerTools();
    const disable = useDisableMcpServer();
    const remove = useDeleteMcpServer();
    const [target, setTarget] = useState<McpServerEntry | null>(null);
    const dataState = servers.isLoading ? 'loading' : servers.isError ? 'error' : !servers.data?.length ? 'empty' : 'ready';
    const mutationError = [handshake, updateTools, disable].find((mutation) => mutation.isError)?.error;
    return <div className="mcp-tools-manual-list" data-testid="admin-mcp-servers" data-state={dataState} aria-busy={servers.isLoading}>
        {servers.isLoading && <p role="status">{t('Loading servers…')}</p>}
        {servers.isError && <div role="alert" data-testid="admin-mcp-servers-error">{t('Failed to load MCP servers')} <Button variant="secondary" size="sm" onClick={() => void servers.refetch()}>{t('Retry')}</Button></div>}
        {dataState === 'empty' && <p className="mcp-tools-state" data-testid="admin-mcp-servers-empty">{t('No manually registered servers.')}</p>}
        {mutationError && <p role="alert" className="mcp-tools-error">{parseLaravelError(mutationError).message}</p>}
        {servers.data?.map((server) => <article key={server.id} className="mcp-tools-server" data-testid={`admin-mcp-server-${server.id}`} data-highlighted={highlightId === server.id}>
            <header><span className="mcp-tools-server-icon"><Terminal size={20} aria-hidden /></span><div><h3>{server.name}</h3><code>{server.transport} · {server.endpoint}</code></div><div className="mcp-tools-actions">
                <Button variant="quiet" size="sm" leadingIcon={<Pause size={14} aria-hidden />} disabled={server.status === 'disabled'} busy={disable.isPending && disable.variables === server.id} onClick={() => disable.mutate(server.id)} data-testid={`admin-mcp-server-${server.id}-disable`}>{t(server.status === 'disabled' ? 'Disabled' : 'Disable')}</Button>
                <Button variant="quiet" size="sm" className="mcp-tools-delete" leadingIcon={<Trash2 size={14} aria-hidden />} data-testid={`admin-mcp-server-${server.id}-delete`} onClick={() => { remove.reset(); setTarget(server); }}>{t('Delete')}</Button>
            </div></header>
            <HandshakeStatus server={server} onRetry={() => handshake.mutate(server.id)} busy={handshake.isPending && handshake.variables === server.id} />
            <ToolMatrix server={server} onSave={(enabledTools) => updateTools.mutate({ id: server.id, enabledTools })} busy={updateTools.isPending && updateTools.variables?.id === server.id} />
        </article>)}
        {target && <Dialog open><DialogContent className="mcp-tools-delete-dialog" showCloseButton={false}><DialogHeader><DialogTitle>{t('Delete this MCP server?')}</DialogTitle><DialogDescription>{t('The registration will be removed. To use the server again, you will need to register it again.')}</DialogDescription></DialogHeader><strong>{target.name}</strong>
            {remove.isError && <p role="alert">{parseLaravelError(remove.error).message}</p>}<div className="mcp-tools-actions"><Button variant="secondary" size="md" disabled={remove.isPending} onClick={() => setTarget(null)} autoFocus>{t('Cancel')}</Button><Button variant="danger" size="md" busy={remove.isPending} onClick={() => remove.mutate(target.id, { onSuccess: () => setTarget(null) })}>{t('Delete server')}</Button></div>
        </DialogContent></Dialog>}
    </div>;
}
