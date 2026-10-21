import { useState } from 'react';
import axios from 'axios';
import { Download, Plug } from 'lucide-react';
import { Button } from '../../components/Button';
import { api, ensureCsrfCookie } from '../../lib/api';
import { useUiTranslation } from '../../lib/ui-locale';
import { AuthLayout } from '../auth/AuthLayout';
import { LoginPage } from '../auth/LoginPage';

export function McpConnectPage() {
    const { t } = useUiTranslation();
    const [connection, setConnection] = useState<{ mcp_url: string; download_url: string }>();
    const [busy, setBusy] = useState(false);
    const [needsLogin, setNeedsLogin] = useState(false);
    const [error, setError] = useState('');
    async function create() {
        setBusy(true); setError('');
        try {
            await ensureCsrfCookie();
            setConnection((await api.post('/api/oauth/mcp-connections')).data);
        } catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 401) setNeedsLogin(true);
            else setError(t('Unable to prepare this connection. Please try again.'));
        } finally { setBusy(false); }
    }
    if (needsLogin) return <LoginPage onSuccess={() => { setNeedsLogin(false); void create(); }}
        onNavigateForgot={() => window.location.assign('/forgot-password')} onNavigateRegister={() => window.location.assign('/register')} />;
    return <AuthLayout title={t('Connect AskMyDocs to Codex')}
        subtitle={t('Search project knowledge and send documents from your development chat.')}
        footer={<a href="/oauth/connections">{t('Manage connected applications')}</a>}>
        <p>{t('Connect your account, then choose one company and one project. Every connection has its own URL, so you can connect several projects separately.')}</p>
        <p>{t('Your account permissions apply to every request. Document imports show a preview for your approval before indexing.')}</p>
        {error && <p role="alert">{error}</p>}
        {!connection && <Button variant="primary" leadingIcon={<Plug size={16} />} busy={busy} onClick={() => void create()}>{t('Prepare Codex connection')}</Button>}
        {connection && <section aria-label={t('Connection ready')} style={{ overflowWrap: 'anywhere' }}>
            <p>{t('Download this connection package. Install its plugin in Codex, select Connect, and finish sign-in in AskMyDocs.')}</p>
            <a href={connection.download_url} className="btn btn-primary"><Download size={16} aria-hidden="true" />{t('Download Codex plugin')}</a>
            <p style={{ fontSize: 12 }}>{t('MCP URL for clients that support adding a server directly:')}</p>
            <code style={{ fontSize: 12 }}>{connection.mcp_url}</code>
            <p style={{ fontSize: 12 }}>{t('No token or client secret is required. Create another connection to connect a second project.')}</p>
            <Button variant="secondary" busy={busy} onClick={() => void create()}>{t('Prepare another connection')}</Button>
        </section>}
    </AuthLayout>;
}
