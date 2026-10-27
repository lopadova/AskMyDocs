import { useState, type FormEvent } from 'react';
import { Terminal, X } from 'lucide-react';
import { Button } from '../../../components/Button';
import { Input } from '../../../components/ui/input';
import { Textarea } from '../../../components/ui/textarea';
import { SelectField, SelectOption } from '../../../components/ui/select-field';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../../../components/ui/dialog';
import { useUiTranslation } from '../../../lib/ui-locale';
import { parseLaravelError } from '../../../lib/laravel-errors';
import { useRegisterMcpServer } from './mcp-tools-hooks';
import type { McpTransport } from './mcp-tools.api';

interface Props { open: boolean; onClose: () => void; onCreated: (id: number) => void }
export function RegisterServerDialog({ open, onClose, onCreated }: Props) {
    const { t } = useUiTranslation();
    const register = useRegisterMcpServer();
    const [name, setName] = useState('');
    const [transport, setTransport] = useState<McpTransport>('stdio');
    const [endpoint, setEndpoint] = useState('');
    const [authJson, setAuthJson] = useState('');
    const [tools, setTools] = useState('*');
    const [error, setError] = useState('');
    const submit = async (event: FormEvent) => {
        event.preventDefault(); setError('');
        let auth: Record<string, unknown> | undefined;
        if (authJson.trim()) {
            try {
                const parsed = JSON.parse(authJson);
                if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error();
                auth = parsed;
            } catch { setError('Authentication settings must be a valid JSON object.'); return; }
        }
        try {
            const result = await register.mutateAsync({ name: name.trim(), transport, endpoint: endpoint.trim(), auth_config: auth,
                enabled_tools: tools.split(',').map((entry) => entry.trim()).filter(Boolean) });
            onCreated(result.id); onClose();
        } catch (cause) { setError(parseLaravelError(cause).message); }
    };
    return <Dialog open={open} onOpenChange={(next) => { if (!next && !register.isPending) onClose(); }}><DialogContent className="mcp-manual-dialog" showCloseButton={false} data-testid="admin-mcp-register-dialog" data-state={register.isPending ? 'submitting' : 'idle'}>
        <DialogHeader className="mcp-manual-header"><span className="mcp-tools-server-icon"><Terminal size={23} aria-hidden /></span><div><DialogTitle>{t('Manual MCP registration')}</DialogTitle><DialogDescription>{t('For local processes and custom integrations. Use Connect server for remote OAuth connections.')}</DialogDescription></div><Button variant="quiet" size="sm" iconOnly aria-label={t('Close dialog')} disabled={register.isPending} onClick={onClose} data-testid="admin-mcp-register-close"><X size={16} aria-hidden /></Button></DialogHeader>
        <form onSubmit={(event) => void submit(event)}><div className="mcp-manual-body">
            <label htmlFor="mcp-manual-name">{t('Name')}</label><Input id="mcp-manual-name" required autoFocus maxLength={100} value={name} disabled={register.isPending} onChange={(event) => setName(event.target.value)} data-testid="admin-mcp-register-name" aria-describedby="mcp-manual-name-help" placeholder={t('For example: Local knowledge server')} /><p id="mcp-manual-name-help">{t('Use a recognizable name for this integration.')}</p>
            <label htmlFor="mcp-manual-transport">{t('Transport')}</label><SelectField id="mcp-manual-transport" value={transport} disabled={register.isPending} onValueChange={(next) => setTransport(next as McpTransport)} data-testid="admin-mcp-register-transport" aria-label={t('MCP transport')}><SelectOption value="stdio">{t('stdio · Local process')}</SelectOption><SelectOption value="http">Streamable HTTP</SelectOption><SelectOption value="sse">Legacy SSE</SelectOption></SelectField>
            <label htmlFor="mcp-manual-endpoint">{t(transport === 'stdio' ? 'Command' : 'Endpoint')}</label><Input id="mcp-manual-endpoint" required type={transport === 'stdio' ? 'text' : 'url'} maxLength={500} value={endpoint} disabled={register.isPending} onChange={(event) => setEndpoint(event.target.value)} data-testid="admin-mcp-register-endpoint" aria-describedby="mcp-manual-endpoint-help" placeholder={transport === 'stdio' ? '/path/to/mcp-server --stdio' : 'https://mcp.example.com/mcp'} /><p id="mcp-manual-endpoint-help">{t(transport === 'stdio' ? 'The command runs on the AskMyDocs server, not on your browser or computer.' : 'Enter the full MCP endpoint, including its path.')}</p>
            <details><summary>{t('Authentication and tool settings')}</summary><div className="mcp-manual-advanced">
                <label htmlFor="mcp-manual-auth">{t('Custom authentication (JSON)')}</label><Textarea id="mcp-manual-auth" rows={4} disabled={register.isPending} value={authJson} onChange={(event) => setAuthJson(event.target.value)} data-testid="admin-mcp-register-auth-json" placeholder={transport === 'stdio' ? '{"env":{"API_TOKEN":"..."}}' : '{"bearer_token":"..."}'} aria-describedby="mcp-manual-auth-help" /><p id="mcp-manual-auth-help">{t('Optional. Encrypted settings for environment variables, tokens, or custom headers.')}</p>
                <label htmlFor="mcp-manual-tools">{t('Allowed tools')}</label><Input id="mcp-manual-tools" value={tools} disabled={register.isPending} onChange={(event) => setTools(event.target.value)} data-testid="admin-mcp-register-enabled-tools" aria-describedby="mcp-manual-tools-help" /><p id="mcp-manual-tools-help">{t('Use * for all discovered tools, comma-separated names for a selection, or leave empty to disable all tools.')}</p>
            </div></details>
            {error && <p role="alert" className="mcp-tools-error" data-testid="admin-mcp-register-error">{t(error)}</p>}
        </div><footer className="mcp-manual-footer"><Button variant="secondary" size="md" disabled={register.isPending} onClick={onClose} data-testid="admin-mcp-register-cancel">{t('Cancel')}</Button><Button type="submit" variant="primary" size="md" busy={register.isPending} disabled={!name.trim() || !endpoint.trim()} data-testid="admin-mcp-register-submit">{t('Register server')}</Button></footer></form>
    </DialogContent></Dialog>;
}
