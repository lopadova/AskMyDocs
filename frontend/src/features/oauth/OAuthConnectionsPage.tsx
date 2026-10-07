import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Unplug } from 'lucide-react';
import { AuthLayout } from '../auth/AuthLayout';
import { LoginPage } from '../auth/LoginPage';
import { Button } from '../../components/Button';
import { disconnectConnection, getConnections, type OAuthConnection } from './oauth.api';

export function OAuthConnectionsPage() {
    const [connections, setConnections] = useState<OAuthConnection[]>();
    const [needsLogin, setNeedsLogin] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState<number | null>(null);
    const load = useCallback(async () => {
        setNeedsLogin(false);
        setError('');
        try { setConnections(await getConnections()); }
        catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 401) setNeedsLogin(true);
            else setError('Unable to load connected applications. Please try again.');
        }
    }, []);
    useEffect(() => { void load(); }, [load]);

    async function disconnect(id: number) {
        setBusy(id);
        setError('');
        try {
            await disconnectConnection(id);
            setConnections((current) => current?.filter((item) => item.id !== id));
        } catch { setError('Unable to revoke this connection. Please try again.'); }
        finally { setBusy(null); }
    }

    if (needsLogin) return <LoginPage onSuccess={() => void load()}
        onNavigateForgot={() => window.location.assign('/forgot-password')}
        onNavigateRegister={() => window.location.assign('/register')} />;

    return <AuthLayout title="Connected applications" subtitle="Manage external applications authorised to use your AskMyDocs account."
        footer={<a href="/app">Return to AskMyDocs</a>}>
        {error && <p role="alert" style={{ color: 'var(--err)', fontSize: 13 }}>{error}</p>}
        {connections === undefined && !error && <p role="status">Loading applications…</p>}
        {connections?.length === 0 && <p>No external applications connected.</p>}
        {connections?.map((item) => <section key={item.id} aria-label={item.name}
            style={{ padding: '16px 0', borderBottom: '1px solid var(--hairline)', overflowWrap: 'anywhere' }}>
            <h2 style={{ fontSize: 15, margin: '0 0 6px' }}>{item.name}</h2>
            <p style={{ fontSize: 12, color: 'var(--fg-2)' }}>{item.tenant_id} · {item.scopes.join(', ')}</p>
            <p style={{ fontSize: 12, color: 'var(--fg-2)' }}>
                {item.expires_at ? `Expires ${new Date(item.expires_at).toLocaleDateString()}` : 'Access revoked'}
            </p>
            <Button variant="danger" size="sm" leadingIcon={<Unplug size={14} />}
                busy={busy === item.id} disabled={busy !== null} onClick={() => void disconnect(item.id)}>Revoke access</Button>
        </section>)}
    </AuthLayout>;
}
