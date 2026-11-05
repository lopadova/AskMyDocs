import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, CalendarDays, Check, ChevronDown, Copy, KeyRound, LockKeyhole, Plus, RefreshCw, ShieldCheck, Unplug, X } from 'lucide-react';
import { Button } from '../../../components/Button';
import { Input } from '../../../components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../../../components/ui/dialog';
import { useUiTranslation } from '../../../lib/ui-locale';
import { useTeamStore } from '../../../lib/team-store';
import { parseLaravelError } from '../../../lib/laravel-errors';
import { mcpTokensApi, type CreatedMcpToken, type McpTenantTokenRow } from './mcp-tokens.api';
import './mcp-tokens.css';

const tokenStatus = (token: McpTenantTokenRow) => token.revoked_at ? 'revoked'
    : token.expires_at && Date.parse(token.expires_at) <= Date.now() ? 'expired' : 'active';

export function McpTokensView() {
    const tenant = useTeamStore((state) => state.currentTeam);
    const team = useTeamStore((state) => state.teams.find((item) => item.tenant_id === state.currentTeam));
    return <TenantTokens key={tenant ?? 'none'} tenant={tenant} organization={team?.name} />;
}

function TenantTokens({ tenant, organization }: { tenant: string | null; organization?: string }) {
    const { t, locale } = useUiTranslation();
    const client = useQueryClient();
    const queryKey = ['mcp-tenant-tokens', tenant];
    const [label, setLabel] = useState('');
    const [expiry, setExpiry] = useState('');
    const [expiryError, setExpiryError] = useState('');
    const [secret, setSecret] = useState<CreatedMcpToken | null>(null);
    const [copyState, setCopyState] = useState<'idle' | 'copied' | 'error'>('idle');
    const [revokeTarget, setRevokeTarget] = useState<McpTenantTokenRow | null>(null);
    const [notice, setNotice] = useState('');
    const tokens = useQuery({ queryKey, queryFn: () => mcpTokensApi.list(tenant), retry: false });
    const createToken = useMutation({
        mutationFn: (values: { label: string; expires_at: string | null }) => mcpTokensApi.create(tenant, values), gcTime: 0,
        onSuccess: (payload) => {
            setSecret(payload); setCopyState('idle'); setLabel(''); setExpiry(''); setNotice('');
            // Keep the one-time secret out of cached rosters and mutation results.
            createToken.reset();
            void client.invalidateQueries({ queryKey });
        },
    });
    const revoke = useMutation({
        mutationFn: (id: number) => mcpTokensApi.revoke(tenant, id),
        onSuccess: (_result, id) => {
            if (secret?.data.id === id) setSecret(null);
            setRevokeTarget(null); setNotice('Token revoked. Applications using it can no longer connect.');
            void client.invalidateQueries({ queryKey });
        },
    });
    const date = (value: string | null, fallback: string) => value && !Number.isNaN(Date.parse(value))
        ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : t(fallback);
    const counts = { active: 0, expired: 0, revoked: 0 };
    for (const row of tokens.data ?? []) counts[tokenStatus(row)]++;
    const error = createToken.isError ? parseLaravelError(createToken.error) : null;
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const expires = expiry ? new Date(expiry) : null;
        if (expires && (Number.isNaN(expires.getTime()) || expires.getTime() <= Date.now())) {
            setExpiryError('Choose an expiration date in the future.'); return;
        }
        if (!label.trim() || secret || createToken.isPending) return;
        setExpiryError('');
        createToken.mutate({ label: label.trim(), expires_at: expires?.toISOString() ?? null });
    };
    const copy = async () => {
        if (!secret) return;
        try { await navigator.clipboard.writeText(secret.plain_token); setCopyState('copied'); }
        catch { setCopyState('error'); }
    };

    return <section className="mcp-tokens-page" data-testid="admin-mcp-tokens-view">
        <header className="mcp-tokens-heading"><span className="mcp-tokens-eyebrow"><KeyRound size={14} aria-hidden />{t('External MCP access')}</span>
            <h1>{t('MCP Tokens')}</h1><p>{t('Give each external application its own access token. Manage its lifecycle from one place.')}</p></header>
        <div className="mcp-tokens-context"><div className="mcp-token-organization"><Building2 size={21} aria-hidden /><div><small>{t('Organization')}</small><strong>{organization ?? t('Current organization')}</strong></div></div>
            <div className="mcp-token-counts" aria-label={t('Token overview')}>{(['active', 'expired', 'revoked'] as const).map((status) => <div key={status}><strong>{tokens.data ? counts[status] : '—'}</strong><span>{t(status === 'active' ? 'Active tokens' : status === 'expired' ? 'Expired tokens' : 'Revoked tokens')}</span></div>)}</div></div>
        {notice && <div className="mcp-tokens-notice" role="status"><Check size={16} aria-hidden />{t(notice)}</div>}
        {secret && <section className="mcp-token-secret" data-testid="admin-mcp-tokens-plain" aria-labelledby="new-mcp-token-title">
            <div className="mcp-token-secret-heading"><span className="mcp-token-mark"><Check size={21} aria-hidden /></span><div><h2 id="new-mcp-token-title">{t('Your token is ready')}</h2><p>{t('Copy it now. The full value is shown only here and cannot be retrieved later.')}</p></div></div>
            <label htmlFor="new-mcp-token">{secret.data.label}</label><Input id="new-mcp-token" className="mcp-token-secret-value" readOnly autoFocus value={secret.plain_token} onFocus={(event) => event.target.select()} aria-describedby="new-mcp-token-help" />
            <div className="mcp-token-secret-actions"><p id="new-mcp-token-help">{t('Store it in the application’s secret settings.')}</p><div>
                <Button variant="secondary" size="md" leadingIcon={copyState === 'copied' ? <Check size={15} aria-hidden /> : <Copy size={15} aria-hidden />} onClick={() => void copy()} data-testid="mcp-token-copy">{t(copyState === 'copied' ? 'Token copied' : 'Copy token')}</Button>
                <Button variant="quiet" size="md" leadingIcon={<X size={15} aria-hidden />} onClick={() => setSecret(null)}>{t('I have saved the token')}</Button>
            </div></div>
            {copyState === 'copied' && <p role="status" className="mcp-token-copy-feedback">{t('Token copied to the clipboard.')}</p>}
            {copyState === 'error' && <p role="alert" className="mcp-token-copy-feedback">{t('Could not copy automatically. Select the token above and copy it manually.')}</p>}
        </section>}
        <div className="mcp-tokens-layout">
            <section className="mcp-tokens-card mcp-token-create" aria-labelledby="mcp-token-create-title">
                <header><span className="mcp-token-mark"><Plus size={20} aria-hidden /></span><div><h2 id="mcp-token-create-title">{t('Create an access token')}</h2></div></header>
                <form onSubmit={submit}>
                    <label htmlFor="mcp-token-label">{t('Token name')}</label><Input id="mcp-token-label" data-testid="admin-mcp-tokens-label" value={label} maxLength={120} required disabled={createToken.isPending} onChange={(event) => { setLabel(event.target.value); createToken.reset(); }} placeholder={t('For example: Customer support assistant')} aria-describedby="mcp-token-label-help" aria-invalid={!!error?.fields.label} />
                    <p id="mcp-token-label-help" className="mcp-token-field-help">{t('Choose one token per application so you can recognize and revoke it independently.')}</p>
                    <details className="mcp-token-expiry-options"><summary><CalendarDays size={15} aria-hidden /><span>{expiry ? date(expiry, 'Expiration date') : t('Set an optional expiration date')}</span><ChevronDown size={14} aria-hidden /></summary><div>
                    <label htmlFor="mcp-token-expiry">{t('Expiration date')} <span>{t('Optional')}</span></label><Input id="mcp-token-expiry" type="datetime-local" value={expiry} disabled={createToken.isPending} aria-describedby="mcp-token-expiry-help" aria-invalid={!!expiryError || !!error?.fields.expires_at} onChange={(event) => { setExpiry(event.target.value); setExpiryError(''); createToken.reset(); }} />
                    <p id="mcp-token-expiry-help" className="mcp-token-field-help">{t('Uses your local time. Leave empty for no expiration; you can revoke access at any time.')}</p></div></details>
                    {(error || expiryError) && <p role="alert" className="mcp-tokens-error">{expiryError ? t(expiryError) : [error?.message, ...Object.values(error?.fields ?? {}).flat()].join(' ')}</p>}
                    <Button type="submit" variant="primary" size="md" leadingIcon={<KeyRound size={15} aria-hidden />} busy={createToken.isPending} disabled={!label.trim() || !!secret} data-testid="admin-mcp-tokens-mint">{t('Mint token')}</Button>
                    <p className="mcp-token-create-note">{t(secret ? 'Save the token above and close its panel before creating another.' : 'The complete token is displayed once, immediately after creation.')}</p>
                    <div className="mcp-token-default-access"><ShieldCheck size={17} aria-hidden /><div><strong>{t('Default access')}</strong><p>{t('MCP reading and tool proposals, within this organization.')}</p><code>mcp:read · mcp:tools:propose</code></div></div>
                </form>
            </section>
            <section className="mcp-tokens-card mcp-token-list" aria-labelledby="mcp-token-list-title">
                <header><div><h2 id="mcp-token-list-title">{t('Application tokens')}</h2><p>{t('Review access, expiration, and recent use.')}</p></div><Button variant="quiet" size="sm" leadingIcon={<RefreshCw size={14} aria-hidden />} busy={tokens.isFetching} onClick={() => void tokens.refetch()}>{t('Refresh tokens')}</Button></header>
                {tokens.isLoading ? <div className="mcp-token-list-state" role="status">{t('Loading tokens…')}</div>
                    : tokens.isError ? <div className="mcp-token-list-state mcp-tokens-error" role="alert"><p>{t('Could not load tokens.')}</p><Button variant="secondary" size="sm" onClick={() => void tokens.refetch()}>{t('Retry')}</Button></div>
                    : !tokens.data?.length ? <div className="mcp-token-empty"><span><KeyRound size={30} strokeWidth={1.4} aria-hidden /></span><h3>{t('No application tokens yet')}</h3><p>{t('Create the first token with the form. Each application will appear here with its own access details.')}</p></div>
                    : <div className="mcp-token-rows">{tokens.data.map((row) => {
                        const status = tokenStatus(row);
                        return <article key={row.id} className="mcp-token-row" data-testid={`admin-mcp-tokens-row-${row.id}`}>
                            <div className="mcp-token-row-heading"><span className="mcp-token-row-icon"><KeyRound size={17} aria-hidden /></span><div><h3>{row.label}</h3><code>•••• {row.token_last4}</code></div><span className="mcp-token-status" data-status={status} data-testid={row.revoked_at ? `admin-mcp-tokens-revoked-${row.id}` : undefined}>{t(status === 'revoked' ? 'Revoked' : status === 'expired' ? 'Expired' : 'Active')}</span></div>
                            <dl className="mcp-token-dates"><div><dt>{t('Created')}</dt><dd>{date(row.created_at, 'Not available')}</dd></div><div><dt>{t('Last used')}</dt><dd>{date(row.last_used_at, 'Never used')}</dd></div><div><dt>{t('Expires')}</dt><dd>{date(row.expires_at, 'No expiration')}</dd></div></dl>
                            <div className="mcp-token-row-footer"><div className="mcp-token-scopes" aria-label={t('Token scopes')}>{row.scopes.length ? row.scopes.map((scope) => <code key={scope}>{scope}</code>) : <span>{t('No scopes assigned')}</span>}</div>{!row.revoked_at && <Button variant="quiet" size="sm" className="mcp-token-revoke" leadingIcon={<Unplug size={14} aria-hidden />} data-testid={`admin-mcp-tokens-revoke-${row.id}`} onClick={() => { revoke.reset(); setRevokeTarget(row); }}>{t('Revoke')}</Button>}</div>
                        </article>;
                    })}</div>}
                <footer className="mcp-token-list-note"><LockKeyhole size={15} aria-hidden /><span>{t('Only the last four characters are kept visible. The full token cannot be displayed again.')}</span></footer>
            </section>
        </div>
        <aside className="mcp-token-guide"><ShieldCheck size={18} aria-hidden /><div><h2>{t('Connect an application')}</h2><p>{t('Create a token, copy it into the MCP client’s authentication settings, then return here to review or revoke access.')}</p></div></aside>
        {revokeTarget && <Dialog open onOpenChange={(open) => { if (!open && !revoke.isPending) setRevokeTarget(null); }}><DialogContent className="mcp-token-revoke-dialog" showCloseButton={false}>
            <DialogHeader><DialogTitle>{t('Revoke this token?')}</DialogTitle><DialogDescription>{t('Applications using this token will lose access immediately. You can create a new token to reconnect them.')}</DialogDescription></DialogHeader>
            <div className="mcp-token-revoke-target"><KeyRound size={18} aria-hidden /><strong>{revokeTarget.label}</strong><code>•••• {revokeTarget.token_last4}</code></div>
            {revoke.isError && <p role="alert" className="mcp-tokens-error">{parseLaravelError(revoke.error, t('Could not revoke the token.')).message}</p>}
            <div className="mcp-token-dialog-actions"><Button variant="secondary" size="md" disabled={revoke.isPending} onClick={() => setRevokeTarget(null)} autoFocus>{t('Cancel')}</Button><Button variant="danger" size="md" leadingIcon={<Unplug size={15} aria-hidden />} busy={revoke.isPending} onClick={() => revoke.mutate(revokeTarget.id)}>{t('Revoke token')}</Button></div>
        </DialogContent></Dialog>}
    </section>;
}
