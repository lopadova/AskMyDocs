import { SelectField, SelectOption } from '@/components/ui/select-field';
import { useUiTranslation } from "../../lib/ui-locale";
import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { ShieldCheck } from 'lucide-react';
import { AuthLayout } from '../auth/AuthLayout';
import { LoginPage } from '../auth/LoginPage';
import { Button } from '../../components/Button';
import { decideConsent, getConsent, type OAuthConsent } from './oauth.api';

export function OAuthConsentPage({ requestId }: { requestId: string }) {
    const { t: translateInterface } = useUiTranslation();
    const [consent, setConsent] = useState<OAuthConsent>();
    const [needsLogin, setNeedsLogin] = useState(false);
    const [error, setError] = useState('');
    const [tenant, setTenant] = useState('');
    const [busy, setBusy] = useState<'approve' | 'deny' | null>(null);
    const load = useCallback(async () => {
        setConsent(undefined);
        setError('');
        setNeedsLogin(false);
        try {
            const data = await getConsent(requestId);
            setConsent(data);
            setTenant(data.teams[0]?.tenant_id ?? '');
        } catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 401) setNeedsLogin(true);
            else setError(axios.isAxiosError(err) ? err.response?.data?.error_description ?? 'Unable to load this connection request.' : 'Unable to load this connection request.');
        }
    }, [requestId]);
    useEffect(() => { void load(); }, [load]);

    async function decide(decision: 'approve' | 'deny') {
        setBusy(decision);
        setError('');
        try {
            const { redirect_to } = await decideConsent(requestId, decision, tenant);
            window.location.assign(redirect_to);
        } catch (err) {
            setError(axios.isAxiosError(err) ? err.response?.data?.error_description ?? 'Unable to complete this connection. Please try again.' : 'Unable to complete this connection. Please try again.');
            if (axios.isAxiosError(err) && err.response?.status === 401) setNeedsLogin(true);
            setBusy(null);
        }
    }

    if (needsLogin) return <LoginPage onSuccess={() => void load()}
        onNavigateForgot={() => window.location.assign('/forgot-password')}
        onNavigateRegister={() => window.location.assign('/register')} />;

    return <AuthLayout title={consent ? `Connect ${consent.client.name}` : 'Connect an application'}
        subtitle={translateInterface("Review the permissions before connecting your AskMyDocs account.")}
        footer={<a href="/oauth/connections">{translateInterface("Manage connected applications")}</a>}>
        {error && <p role="alert" style={{ color: 'var(--err)', fontSize: 13 }}>{error}</p>}
        {!consent && !error && <p role="status">{translateInterface("Loading connection request…")}</p>}
        {consent && <form onSubmit={(event) => { event.preventDefault(); void decide('approve'); }}>
            <p style={{ fontSize: 13, color: 'var(--fg-2)', overflowWrap: 'anywhere' }}>{translateInterface("Signed in as") + " "}<strong>{consent.user.email}</strong></p>
            <p style={{ fontSize: 13, overflowWrap: 'anywhere' }}>
                <strong>{consent.client.name}</strong> ({new URL(consent.client.redirect_uri).origin}{translateInterface(") will be able to:")}</p>
            <ul style={{ paddingLeft: 20, fontSize: 13, lineHeight: 1.8 }}>
                {consent.scopes.map((scope) => <li key={scope.id}>{scope.description}</li>)}
            </ul>
            <label htmlFor="oauth-tenant" style={{ display: 'block', marginBottom: 6, fontSize: 13 }}>{translateInterface("Company")}</label>
            <SelectField id="oauth-tenant" className="input" value={tenant} disabled={busy !== null || consent.teams.length === 0}
                onValueChange={(value) => setTenant(value)} style={{ width: '100%', marginBottom: 12 }}>
                {consent.teams.length === 0 && <SelectOption value="">{translateInterface("No company available")}</SelectOption>}
                {consent.teams.map((team) => <SelectOption key={team.tenant_id} value={team.tenant_id}>{team.name}</SelectOption>)}
            </SelectField>
            {consent.teams.length === 0 && <p role="status" style={{ fontSize: 13 }}>{translateInterface("You need membership in an active company to connect this application.")}</p>}
            <p style={{ fontSize: 12, color: 'var(--fg-2)' }}>
                {translateInterface("The connection can access this company for up to") + " "}{consent.token_ttl_days} {" " + translateInterface("days, using your document permissions. You can revoke access at any time.")}</p>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, marginTop: 20 }}>
                <Button type="submit" variant="primary" leadingIcon={<ShieldCheck size={16} />}
                    busy={busy === 'approve'} disabled={busy !== null || tenant === ''}>{translateInterface("Connect application")}</Button>
                <Button variant="secondary" busy={busy === 'deny'} disabled={busy !== null}
                    onClick={() => void decide('deny')}>{translateInterface("Cancel")}</Button>
            </div>
        </form>}
    </AuthLayout>;
}
