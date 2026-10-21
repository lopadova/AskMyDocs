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
    const [project, setProject] = useState('');
    const projects = consent?.teams.find((team) => team.tenant_id === tenant)?.projects ?? [];
    const [busy, setBusy] = useState<'approve' | 'deny' | null>(null);
    const load = useCallback(async () => {
        setConsent(undefined);
        setError('');
        setNeedsLogin(false);
        try {
            const data = await getConsent(requestId);
            setConsent(data);
            setTenant(data.requires_explicit_selection ? '' : data.teams[0]?.tenant_id ?? '');
            setProject(data.requires_explicit_selection ? '' : data.teams[0]?.projects?.[0]?.project_key ?? '');
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
            const { redirect_to } = await (consent?.requires_project ? decideConsent(requestId, decision, tenant, project) : decideConsent(requestId, decision, tenant));
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
            {consent.delegation_available === false && <p role="status" style={{ fontSize: 13, color: 'var(--fg-2)' }}>
                {translateInterface("You can connect this application now. Delegated tasks are not available yet.")}
            </p>}
            <label htmlFor="oauth-tenant" style={{ display: 'block', marginBottom: 6, fontSize: 13 }}>{translateInterface("Company")}</label>
            <SelectField id="oauth-tenant" className="input" value={tenant} disabled={busy !== null || consent.teams.length === 0}
                onValueChange={(value) => { setTenant(value); setProject(consent.requires_explicit_selection ? '' : consent.teams.find((team) => team.tenant_id === value)?.projects?.[0]?.project_key ?? ''); }} style={{ width: '100%', marginBottom: 12 }}>
                {consent.requires_explicit_selection && <SelectOption value="">{translateInterface("Choose a company")}</SelectOption>}
                {consent.teams.length === 0 && <SelectOption value="">{translateInterface("No company available")}</SelectOption>}
                {consent.teams.map((team) => <SelectOption key={team.tenant_id} value={team.tenant_id}>{team.name}</SelectOption>)}
            </SelectField>
            {consent.requires_project && <>
                <label htmlFor="oauth-project" style={{ display: 'block', marginBottom: 6, fontSize: 13 }}>{translateInterface("Project")}</label>
                <SelectField key={tenant} id="oauth-project" className="input" value={project} disabled={busy !== null || projects.length === 0}
                    onValueChange={setProject} style={{ width: '100%', marginBottom: 12 }}>
                    {consent.requires_explicit_selection && <SelectOption value="">{translateInterface("Choose a project")}</SelectOption>}
                    {projects.map((item) => <SelectOption key={item.project_key} value={item.project_key}>{item.project_key}</SelectOption>)}
                </SelectField>
                <p style={{ fontSize: 12, color: 'var(--fg-2)' }}>{translateInterface("This connection can access only this project. Create a separate connection for another project.")}</p>
            </>}
            {consent.teams.length === 0 && <p role="status" style={{ fontSize: 13 }}>{translateInterface("You need membership in an active company to connect this application.")}</p>}
            <p style={{ fontSize: 12, color: 'var(--fg-2)' }}>
                {translateInterface("The connection can access this company for up to") + " "}{consent.token_ttl_days} {" " + translateInterface(consent.token_ttl_days === 1 ? "day, using your document permissions. You can revoke access at any time." : "days, using your document permissions. You can revoke access at any time.")}</p>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, marginTop: 20 }}>
                <Button type="submit" variant="primary" leadingIcon={<ShieldCheck size={16} />}
                    busy={busy === 'approve'} disabled={busy !== null || tenant === '' || (consent.requires_project === true && project === '')}>{translateInterface("Connect application")}</Button>
                <Button variant="secondary" busy={busy === 'deny'} disabled={busy !== null}
                    onClick={() => void decide('deny')}>{translateInterface("Cancel")}</Button>
            </div>
        </form>}
    </AuthLayout>;
}
