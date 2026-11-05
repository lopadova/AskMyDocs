import type { FormEvent, ReactNode } from 'react';
import { Globe2, KeyRound, LockKeyhole, Network, Settings2, ShieldCheck, X } from 'lucide-react';
import { Button } from '../../components/Button';
import { Input } from '../../components/ui/input';
import { SelectField, SelectOption } from '../../components/ui/select-field';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../../components/ui/dialog';
import { useUiTranslation } from '../../lib/ui-locale';
import type { CreateMcpConnectionPayload } from './mcp-connections.api';
import type { McpProjectOption } from './McpConnectionsPanel';
import './mcp-connection-dialog.css';

interface Props {
    scope: 'shared' | 'personal';
    form: CreateMcpConnectionPayload;
    onChange: (form: CreateMcpConnectionPayload) => void;
    onSubmit: (event: FormEvent) => void;
    onClose: () => void;
    busy: boolean;
    projects: McpProjectOption[];
    projectsLoading: boolean;
    projectsError: boolean;
    oauthEnabled?: boolean;
    error?: ReactNode;
}

export function McpConnectionDialog({ scope, form, onChange, onSubmit, onClose, busy, projects, projectsLoading, projectsError, oauthEnabled = true, error }: Props) {
    const { t } = useUiTranslation();
    const change = (values: Partial<CreateMcpConnectionPayload>) => onChange({ ...form, ...values });
    return <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose(); }}>
        <DialogContent className="mcp-connect-dialog" showCloseButton={false} data-testid={`mcp-connection-form-${scope}`}>
            <DialogHeader className="mcp-connect-header"><span className="mcp-connect-mark"><Network size={23} aria-hidden /></span><div>
                <DialogTitle>{t('New MCP connection')}</DialogTitle><DialogDescription>{t('Connect a server, sign in, and choose which tools your agent can use.')}</DialogDescription>
            </div><Button variant="quiet" size="sm" iconOnly disabled={busy} aria-label={t('Close MCP connection form')} data-testid="mcp-connection-form-close" onClick={onClose}><X size={16} aria-hidden /></Button></DialogHeader>
            <form onSubmit={onSubmit}>
                <div className="mcp-connect-body">
                    {error}
                    <Field id="mcp-connect-name" label={t('Name')} hint={t('A recognizable name, for example: Orders or Company documents.')}>
                        <Input id="mcp-connect-name" required autoFocus maxLength={100} disabled={busy} data-testid="mcp-connection-name" value={form.name} onChange={(event) => change({ name: event.target.value })} aria-describedby="mcp-connect-name-help" placeholder={t('For example: Company documents')} />
                    </Field>
                    <Field id="mcp-connect-endpoint" label={t('MCP endpoint')} hint={t('Paste the MCP server URL provided by the service, including its path.')}>
                        <Input id="mcp-connect-endpoint" required type="url" maxLength={2048} disabled={busy} data-testid="mcp-connection-endpoint" value={form.endpoint} onChange={(event) => change({ endpoint: event.target.value })} aria-describedby="mcp-connect-endpoint-help" placeholder="https://mcp.example.com/mcp" />
                    </Field>
                    <fieldset className="mcp-connect-auth" disabled={busy}><legend>{t('Authentication')}</legend><div className="mcp-connect-auth-options">
                        {([
                            { id: 'oauth', title: 'OAuth', detail: 'Sign in with the service', icon: ShieldCheck },
                            { id: 'bearer', title: 'Bearer token', detail: 'Use an existing token', icon: KeyRound },
                            { id: 'none', title: 'No authentication', detail: 'Public endpoints only', icon: Globe2 },
                        ] as const).map(({ id, title, detail, icon: Icon }) => <label key={id} className="mcp-connect-choice" data-selected={form.auth_method === id} data-disabled={id === 'oauth' && !oauthEnabled}>
                            <Icon size={19} aria-hidden /><span><strong>{t(title)}</strong><small>{t(detail)}</small></span><input type="radio" name="mcp-auth-method" data-testid={`mcp-auth-${id}`} checked={form.auth_method === id} disabled={id === 'oauth' && !oauthEnabled} onChange={() => change({ auth_method: id, bearer: id === 'bearer' ? form.bearer : '' })} />
                        </label>)}
                    </div><p className="mcp-connect-help">{t(!oauthEnabled ? 'OAuth is disabled for this installation.' : form.auth_method === 'oauth' ? 'Continue to the service’s consent page, then return here. You do not need to paste access tokens.' : form.auth_method === 'bearer' ? 'The token is stored encrypted and is never shown in the connection list.' : 'Choose this only when the server accepts requests without credentials.')}</p></fieldset>
                    {form.auth_method === 'bearer' && <Field id="mcp-connect-bearer" label={t('Bearer token')} hint={t('Paste only the token value, without the Bearer prefix.')}><Input id="mcp-connect-bearer" data-testid="mcp-connection-bearer" type="password" autoComplete="new-password" required maxLength={8192} disabled={busy} value={form.bearer ?? ''} onChange={(event) => change({ bearer: event.target.value })} aria-describedby="mcp-connect-bearer-help" /></Field>}
                    <Field id="mcp-connect-project" label={t('Project (optional)')} hint={t(projectsError ? 'Projects could not be loaded.' : 'Choose where shared resources will be indexed. Leave the organization default if unsure.')}>
                        <SelectField id="mcp-connect-project" data-testid="mcp-connection-project" aria-describedby="mcp-connect-project-help" value={form.project_key ?? ''} disabled={busy || projectsLoading} onValueChange={(value) => change({ project_key: value || null })}>
                            <SelectOption value="">{t(projectsLoading ? 'Loading projects…' : 'Tenant default')}</SelectOption>
                            {projects.map((project) => <SelectOption key={project.project_key} value={project.project_key}>{project.name ? `${project.name} · ${project.project_key}` : project.project_key}</SelectOption>)}
                        </SelectField>
                    </Field>
                    <details className="mcp-connect-advanced"><summary><Settings2 size={15} aria-hidden />{t('Advanced settings')}<span>{t(form.transport === 'auto' ? 'Auto' : form.transport === 'legacy_sse' ? 'Legacy SSE' : 'Streamable HTTP')}</span></summary>
                        <Field id="mcp-connect-label" label={t('Label')} hint={t('Optional display name. Otherwise the server name is used.')}><Input id="mcp-connect-label" data-testid="mcp-connection-label" maxLength={100} disabled={busy} value={form.label ?? ''} onChange={(event) => change({ label: event.target.value })} /></Field>
                        <fieldset className="mcp-connect-transports" disabled={busy}><legend>{t('Transport')}</legend>{([
                            ['auto', 'Auto', 'Choose the best protocol'], ['streamable_http', 'Streamable HTTP', 'Modern HTTP transport'], ['legacy_sse', 'Legacy SSE', 'For older MCP servers'],
                        ] as const).map(([value, title, detail]) => <label key={value}><input type="radio" name="mcp-transport" data-testid={`mcp-transport-${value}`} checked={form.transport === value} onChange={() => change({ transport: value })} /><span><strong>{t(title)}</strong><small>{t(detail)}</small></span></label>)}</fieldset>
                    </details>
                </div>
                <footer className="mcp-connect-footer"><span><LockKeyhole size={14} aria-hidden />{t('Tools are reviewed after connection.')}</span><div><Button variant="secondary" size="md" disabled={busy} data-testid="mcp-connection-cancel" onClick={onClose}>{t('Cancel')}</Button>
                    <Button type="submit" variant="primary" size="md" busy={busy} leadingIcon={<ShieldCheck size={15} aria-hidden />} disabled={!form.name.trim() || !form.endpoint.trim() || (form.auth_method === 'oauth' && !oauthEnabled)} data-testid="mcp-connection-submit">{t(form.auth_method === 'oauth' ? 'Continue with OAuth' : 'Connect and discover')}</Button></div></footer>
            </form>
        </DialogContent>
    </Dialog>;
}

function Field({ id, label, hint, children }: { id: string; label: string; hint?: string; children: ReactNode }) {
    return <div className="mcp-connect-field"><label htmlFor={id}>{label}</label>{children}{hint && <p id={`${id}-help`} className="mcp-connect-help">{hint}</p>}</div>;
}
