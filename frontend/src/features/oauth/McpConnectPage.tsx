import { useState } from 'react';
import axios from 'axios';
import { Copy, Download, Plug } from 'lucide-react';
import { Button } from '../../components/Button';
import { SelectField, SelectOption } from '../../components/ui/select-field';
import { api, ensureCsrfCookie } from '../../lib/api';
import { useUiTranslation } from '../../lib/ui-locale';
import { AuthLayout } from '../auth/AuthLayout';
import { LoginPage } from '../auth/LoginPage';

type McpClient = 'codex' | 'claude' | 'claude-code' | 'cursor' | 'other';
type Connection = { id: string; mcp_url: string; download_url: string };
const shellQuote = (value: string) => "'" + value.replace(/'/g, "'\\''") + "'";

export function McpConnectPage() {
    const { t } = useUiTranslation();
    const [client, setClient] = useState<McpClient>('codex');
    const [connection, setConnection] = useState<Connection>();
    const [busy, setBusy] = useState(false);
    const [needsLogin, setNeedsLogin] = useState(false);
    const [error, setError] = useState('');
    const [copyStatus, setCopyStatus] = useState('');
    async function create() {
        setBusy(true); setError(''); setCopyStatus('');
        try {
            await ensureCsrfCookie();
            setConnection((await api.post('/api/oauth/mcp-connections')).data);
        } catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 401) setNeedsLogin(true);
            else setError(t('Unable to prepare this connection. Please try again.'));
        } finally { setBusy(false); }
    }
    async function copy(value: string) {
        try {
            await navigator.clipboard.writeText(value);
            setCopyStatus('Copied to clipboard.');
        } catch {
            setCopyStatus('Copy is unavailable. Select and copy the text above.');
        }
    }
    const serverName = connection ? `askmydocs-${connection.id}` : '';
    const codexAddCommand = connection ? `codex mcp add ${shellQuote(serverName)} --url ${shellQuote(connection.mcp_url)}` : '';
    const codexLoginCommand = connection ? `codex mcp login ${shellQuote(serverName)}` : '';
    const claudeCommand = connection ? `claude mcp add --transport http ${shellQuote(serverName)} ${shellQuote(connection.mcp_url)}` : '';
    const cursorConfig = connection ? JSON.stringify({ mcpServers: { [serverName]: { url: connection.mcp_url } } }, null, 2) : '';
    const codeStyle = { fontSize: 12, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' } as const;
    if (needsLogin) return <LoginPage onSuccess={() => { setNeedsLogin(false); void create(); }}
        onNavigateForgot={() => window.location.assign('/forgot-password')} onNavigateRegister={() => window.location.assign('/register')} />;
    return <AuthLayout title={t('Connect AskMyDocs to your AI tools')}
        subtitle={t('Search project knowledge and send documents from your development chat.')}
        footer={<a href="/oauth/connections">{t('Manage connected applications')}</a>}>
        <p>{t('Connect your account, then choose one company and one project. Every connection has its own URL, so you can connect several projects separately.')}</p>
        <p>{t('Your account permissions apply to every request. Document imports show a preview for your approval before indexing.')}</p>
        <label htmlFor="mcp-client" style={{ display: 'block', marginBottom: 6 }}>{t('Application')}</label>
        <SelectField id="mcp-client" value={client} onValueChange={(value) => { setClient(value as McpClient); setCopyStatus(''); }} style={{ width: '100%', marginBottom: 12 }}>
            <SelectOption value="codex">Codex</SelectOption>
            <SelectOption value="claude">{t('Claude web / Desktop')}</SelectOption>
            <SelectOption value="claude-code">Claude Code</SelectOption>
            <SelectOption value="cursor">Cursor</SelectOption>
            <SelectOption value="other">{t('Other MCP client')}</SelectOption>
        </SelectField>
        {client === 'claude' && <p role="note">{t('Claude web connectors require a public HTTPS URL, including in Claude Desktop. Anthropic cannot reach a local .test address.')}</p>}
        {error && <p role="alert">{error}</p>}
        {!connection && <Button variant="primary" leadingIcon={<Plug size={16} />} busy={busy} onClick={() => void create()}>{t('Prepare connection')}</Button>}
        {connection && <section aria-label={t('Connection ready')} style={{ overflowWrap: 'anywhere' }}>
            <p style={{ fontSize: 12 }}>{t('MCP connection URL')}</p>
            <pre style={codeStyle}>{connection.mcp_url}</pre>
            <Button variant={client === 'claude' || client === 'other' ? 'primary' : 'secondary'} leadingIcon={<Copy size={16} />} onClick={() => void copy(connection.mcp_url)}>{t('Copy MCP URL')}</Button>
            {client === 'codex' && <>
                <p>{t('Download this connection package. Install its plugin in Codex, select Connect, and finish sign-in in AskMyDocs.')}</p>
                <a href={connection.download_url} className="btn btn-primary"><Download size={16} aria-hidden="true" />{t('Download Codex plugin')}</a>
                <p>{t('Start a new Codex chat after installing the plugin. When you select Connect, your browser opens AskMyDocs for sign-in, company and project selection, and consent. Codex stores the resulting token automatically.')}</p>
                <details style={{ marginTop: 12 }}>
                    <summary>{t('Connect through the Codex CLI')}</summary>
                    <p>{t('Alternatively, register this URL directly in Codex. Use the login command to open or repeat browser sign-in.')}</p>
                    <pre style={codeStyle}>{codexAddCommand}</pre>
                    <Button variant="secondary" leadingIcon={<Copy size={16} />} onClick={() => void copy(codexAddCommand)}>{t('Copy setup command')}</Button>
                    <pre style={codeStyle}>{codexLoginCommand}</pre>
                    <Button variant="secondary" leadingIcon={<Copy size={16} />} onClick={() => void copy(codexLoginCommand)}>{t('Copy login command')}</Button>
                </details>
            </>}
            {client === 'claude' && <ol style={{ paddingLeft: 20, fontSize: 13, lineHeight: 1.7 }}>
                <li>{t('In Claude, open Customize, Connectors, Add, Custom, Web. Enter a name and the MCP URL above.')}</li>
                <li>{t('Choose Sign in now and Register automatically for the OAuth client. No client ID or secret is needed.')}</li>
                <li>{t('Finish sign-in in AskMyDocs, choose one company and one project, and approve the connection.')}</li>
            </ol>}
            {client === 'claude-code' && <>
                <p>{t('Run this command in your development project, then open /mcp in Claude Code and sign in.')}</p>
                <pre style={codeStyle}>{claudeCommand}</pre>
                <Button variant="primary" leadingIcon={<Copy size={16} />} onClick={() => void copy(claudeCommand)}>{t('Copy command')}</Button>
            </>}
            {client === 'cursor' && <>
                <p>{t('Add this entry to your Cursor MCP configuration, keeping any existing servers. Enable the server and complete OAuth sign-in.')}</p>
                <pre style={codeStyle}>{cursorConfig}</pre>
                <Button variant="primary" leadingIcon={<Copy size={16} />} onClick={() => void copy(cursorConfig)}>{t('Copy configuration')}</Button>
            </>}
            {client === 'other' && <p>{t('Add a remote MCP server with this URL. The client must support Streamable HTTP and OAuth with automatic client registration. Complete sign-in in the browser.')}</p>}
            {copyStatus && <p role="status" style={{ fontSize: 12 }}>{t(copyStatus)}</p>}
            <p style={{ fontSize: 12 }}>{t('No token or client secret is required. Create another connection to connect a second project.')}</p>
            <p style={{ fontSize: 12 }}>{t('Search, document reading and document imports use the same project permissions in every client. Sending a local file depends on the client’s file access.')}</p>
            <Button variant="secondary" busy={busy} onClick={() => void create()}>{t('Prepare another connection')}</Button>
        </section>}
    </AuthLayout>;
}
