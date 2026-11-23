import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button } from '../../../components/Button';
import { Input } from '../../../components/ui/input';
import { Textarea } from '../../../components/ui/textarea';
import { useTeamStore } from '../../../lib/team-store';
import { useUiTranslation } from '../../../lib/ui-locale';
import type { WidgetMode } from '../../../widget/types';
import { toAdminError } from '../shared/errors';
import { CopyButton } from './CopyButton';
import { EmbedCodeDialog } from './EmbedCodeDialog';
import { parseOrigins } from './WidgetOriginsDialog';
import { WidgetSetupDocuments } from './WidgetSetupDocuments';
import { widgetProjectHandoff } from './widget-project-handoff';
import { widgetSetupApi, type WidgetSetup, type WidgetSetupInput, type WidgetSetupKey, type WidgetSetupSummary } from './widget-project-setup.api';

const selectClass = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
const setupKey = (team: string | null) => ['admin-widget-setups', team] as const;
const steps = ['Project', 'Developer handoff', 'Documents', 'Embed'] as const;
type Step = typeof steps[number];

interface Selection {
    id: number;
    fromCreation?: boolean;
}

export function WidgetProjectSetupView({ onManageKeys }: { onManageKeys: () => void }) {
    const { t } = useUiTranslation();
    const team = useTeamStore((state) => state.currentTeam);
    const [selection, setSelection] = useState<Selection | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const list = useQuery({ queryKey: setupKey(team), queryFn: widgetSetupApi.list });

    if (selection) {
        return <SetupDetails key={selection.id} selection={selection} onClose={() => setSelection(null)} onManageKeys={onManageKeys} />;
    }

    return (
        <section className="grid max-w-3xl gap-4 py-2" aria-labelledby="widget-setup-heading" data-testid="widget-setup-view">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id="widget-setup-heading" className="text-lg font-semibold">{t('Widget setup')}</h2>
                {!createOpen && <Button variant="primary" data-testid="widget-setup-new" onClick={() => setCreateOpen(true)}>{t('Set up a new widget')}</Button>}
            </div>
            {createOpen && (
                <ProjectStep
                    team={team}
                    savedSetups={list.data ?? []}
                    onClose={() => setCreateOpen(false)}
                    onSelect={(next) => { setCreateOpen(false); setSelection(next); }}
                />
            )}
            {list.isPending && <p role="status" className="text-sm text-muted-foreground">{t('Loading…')}</p>}
            {list.isError && (
                <div role="alert" className="flex flex-wrap items-center gap-2 text-sm text-destructive">
                    {toAdminError(list.error).message}
                    <Button size="sm" busy={list.isFetching} data-testid="widget-setup-list-retry" onClick={() => void list.refetch()}>{t('Retry')}</Button>
                </div>
            )}
            {list.data && list.data.length > 0 && (
                <ul className="divide-y divide-border rounded-md border border-border" aria-label={t('Saved widget setups')}>
                    {list.data.map((item) => (
                        <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
                            <div className="min-w-0 text-sm [overflow-wrap:anywhere]">
                                <p className="font-medium">{item.name}</p>
                                <p className="text-xs text-muted-foreground">{item.project_key}</p>
                            </div>
                            <Button size="sm" aria-label={t('Open setup for {name}', { name: item.name })} data-testid={`widget-setup-resume-${item.id}`} onClick={() => setSelection({ id: item.id })}>
                                {t(item.widget_key_id ? 'Open setup' : 'Resume setup')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function ProjectStep({ team, savedSetups, onClose, onSelect }: {
    team: string | null;
    savedSetups: WidgetSetupSummary[];
    onClose: () => void;
    onSelect: (selection: Selection) => void;
}) {
    const { t, locale } = useUiTranslation();
    const client = useQueryClient();
    const [input, setInput] = useState<WidgetSetupInput>({
        name: '', project_key: '', user_auth_enabled: false, language: locale === 'it' ? 'Italiano' : 'English', audience: '', topics: '',
    });
    const [keyEdited, setKeyEdited] = useState(false);
    const create = useMutation({
        mutationFn: widgetSetupApi.create,
        onSuccess: (setup) => {
            void client.invalidateQueries({ queryKey: setupKey(team) });
            void client.invalidateQueries({ queryKey: ['admin-projects'] });
            void client.invalidateQueries({ queryKey: ['admin', 'kb', 'projects'] });
            onSelect({ id: setup.id, fromCreation: true });
        },
        onError: () => void client.invalidateQueries({ queryKey: setupKey(team) }),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!create.isPending) create.mutate(input);
    };
    const error = create.isError ? toAdminError(create.error) : null;
    const existing = error?.fieldErrors.project_key ? savedSetups.find((item) => item.project_key === input.project_key) : null;

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-md border border-border p-4">
            <fieldset className="grid gap-3" disabled={create.isPending}>
                <legend className="mb-3 text-base font-medium">{t('Create project')}</legend>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="grid gap-1 text-sm">
                        {t('Name')}
                        <Input autoFocus required maxLength={200} value={input.name} data-testid="widget-setup-name" onChange={(event) => {
                            const name = event.target.value;
                            const slug = name.normalize('NFKD').replace(/\p{Diacritic}/gu, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
                            setInput({ ...input, name, project_key: keyEdited ? input.project_key : slug.slice(0, 120).replace(/-$/, '') });
                        }} />
                    </label>
                    <label className="grid gap-1 text-sm">
                        {t('Project key')}
                        <Input maxLength={120} pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value={input.project_key} data-testid="widget-setup-project-key" onChange={(event) => {
                            setKeyEdited(true);
                            setInput({ ...input, project_key: event.target.value });
                        }} />
                        <span className="text-xs text-muted-foreground">{t('The project key is permanent after creation.')}</span>
                    </label>
                    <label className="grid gap-1 text-sm">
                        {t('Widget access')}
                        <select className={selectClass} value={input.user_auth_enabled ? 'authenticated' : 'public'} data-testid="widget-setup-access" onChange={(event) => setInput({ ...input, user_auth_enabled: event.target.value === 'authenticated' })}>
                            <option value="public">{t('Public website')}</option>
                            <option value="authenticated">{t('Website or application with login')}</option>
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        {t('Document language')}
                        <Input required maxLength={80} value={input.language} data-testid="widget-setup-language" onChange={(event) => setInput({ ...input, language: event.target.value })} />
                    </label>
                </div>
                <label className="grid gap-1 text-sm">
                    {t('Audience')}
                    <Input required maxLength={200} value={input.audience} data-testid="widget-setup-audience" onChange={(event) => setInput({ ...input, audience: event.target.value })} />
                </label>
                <label className="grid gap-1 text-sm">
                    {t('Topics to cover')}
                    <Textarea required rows={3} maxLength={4000} value={input.topics} data-testid="widget-setup-topics" onChange={(event) => setInput({ ...input, topics: event.target.value })} />
                </label>
            </fieldset>
            {error && <p role="alert" className="text-sm text-destructive">{error.message}</p>}
            {existing && (
                <div><Button size="sm" data-testid="widget-setup-resume-duplicate" onClick={() => onSelect({ id: existing.id })}>{t('Resume setup')}</Button></div>
            )}
            <div className="flex flex-wrap justify-end gap-2">
                <Button disabled={create.isPending} onClick={onClose} data-testid="widget-setup-cancel-create">{t('Cancel')}</Button>
                <Button variant="primary" type="submit" busy={create.isPending} data-testid="widget-setup-create">{t('Create project')}</Button>
            </div>
        </form>
    );
}

function SetupDetails({ selection, onClose, onManageKeys }: { selection: Selection; onClose: () => void; onManageKeys: () => void }) {
    const { t, locale } = useUiTranslation();
    const team = useTeamStore((state) => state.currentTeam);
    const [selectedStep, setStep] = useState<Step | null>(selection.fromCreation ? 'Developer handoff' : null);
    const heading = useRef<HTMLHeadingElement>(null);
    const detail = useQuery({
        queryKey: [...setupKey(team), selection.id],
        queryFn: () => widgetSetupApi.get(selection.id),
        refetchInterval: (query) => query.state.data?.is_indexing ? 2000 : false,
    });
    const setup = detail.data;
    const step = selectedStep ?? (setup?.key ? 'Embed' : 'Documents');
    const ready = Boolean(setup?.key || (setup && setup.indexed_documents > 0 && !setup.is_indexing));
    useEffect(() => { if (setup) heading.current?.focus(); }, [step, setup?.id]);

    const handoff = setup ? widgetProjectHandoff(setup, locale) : '';
    const download = () => {
        if (!setup) return;
        const url = URL.createObjectURL(new Blob([handoff], { type: 'text/markdown;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `${setup.project_key}-widget-handoff.md`;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 0);
    };

    return (
        <section className="grid max-w-3xl gap-4 py-2" data-testid="widget-setup-details" aria-labelledby="widget-setup-step-heading">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium">{setup?.name ?? t('Widget setup')}</p>
                <Button size="sm" variant="quiet" onClick={onClose} data-testid="widget-setup-back-list">{t('Back to widget setups')}</Button>
            </div>
            <ol className="flex flex-wrap gap-x-4 gap-y-1 border-b border-border pb-2 text-xs text-muted-foreground" aria-label={t('Setup steps')}>
                {steps.map((item) => (
                    <li key={item} aria-current={item === step ? 'step' : undefined} className={item === step ? 'font-semibold text-foreground' : undefined}>{t(item)}</li>
                ))}
            </ol>
            <h2 id="widget-setup-step-heading" className="text-lg font-semibold" ref={heading} tabIndex={-1}>{t(step)}</h2>
            {detail.isPending && <p role="status" className="text-sm text-muted-foreground">{t('Loading…')}</p>}
            {detail.isError && (
                <div role="alert" className="flex flex-wrap items-center gap-2 text-sm text-destructive">
                    {toAdminError(detail.error).message}
                    <Button size="sm" busy={detail.isFetching} data-testid="widget-setup-detail-retry" onClick={() => void detail.refetch()}>{t('Retry')}</Button>
                </div>
            )}
            {setup && step === 'Developer handoff' && (
                <>
                    <p className="text-sm text-muted-foreground">{t('Give this brief to the developers. You can return here when the documents are ready.')}</p>
                    <pre tabIndex={0} className="max-h-80 overflow-auto whitespace-pre-wrap rounded-md border border-border bg-muted p-4 text-xs leading-relaxed [overflow-wrap:anywhere]" data-testid="widget-setup-handoff">{handoff}</pre>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap gap-2">
                            <CopyButton value={handoff} label={t('Copy handoff')} testId="widget-setup-copy-handoff" />
                            <Button size="sm" data-testid="widget-setup-download" onClick={download}>{t('Download Markdown')}</Button>
                        </div>
                        <Button variant="primary" onClick={() => setStep('Documents')} data-testid="widget-setup-continue-documents">{t('Continue to documents')}</Button>
                    </div>
                </>
            )}
            {setup && step === 'Documents' && (
                <>
                    <WidgetSetupDocuments setup={setup} refresh={() => void detail.refetch()} />
                    <div className="flex flex-wrap justify-between gap-2">
                        <Button onClick={() => setStep('Developer handoff')} data-testid="widget-setup-back-handoff">{t('Back to handoff')}</Button>
                        <Button variant="primary" disabled={!ready || detail.isError} onClick={() => setStep('Embed')} data-testid="widget-setup-continue-embed">{t('Continue to embed')}</Button>
                    </div>
                </>
            )}
            {setup && step === 'Embed' && (
                <EmbedStep setup={setup} team={team} ready={ready && !detail.isError} onBack={() => setStep('Documents')} onManageKeys={onManageKeys} />
            )}
        </section>
    );
}

function EmbedStep({ setup, team, ready, onBack, onManageKeys }: { setup: WidgetSetup; team: string | null; ready: boolean; onBack: () => void; onManageKeys: () => void }) {
    const { t } = useUiTranslation();
    const client = useQueryClient();
    const [label, setLabel] = useState(setup.name.slice(0, 120));
    const [origins, setOrigins] = useState('');
    const [mode, setMode] = useState<WidgetMode>('helper');
    const [acceptPartial, setAcceptPartial] = useState(false);
    const [embed, setEmbed] = useState<{ key: WidgetSetupKey; identitySecret?: string | null } | null>(null);
    const issue = useMutation({
        gcTime: 0,
        mutationFn: () => widgetSetupApi.issue(setup.id, { label, mode, allowed_origins: parseOrigins(origins), accept_partial: acceptPartial }),
        onSuccess: (response) => {
            setEmbed({ key: response.data, identitySecret: response.identity_plain_secret });
            client.setQueryData<WidgetSetup>([...setupKey(team), setup.id], (current) => current && {
                ...current, key: response.data, widget_key_id: response.data.id,
            });
            void client.invalidateQueries({ queryKey: setupKey(team) });
            void client.invalidateQueries({ queryKey: ['admin-widget-keys'] });
        },
        onError: () => void client.invalidateQueries({ queryKey: [...setupKey(team), setup.id] }),
    });

    const key = setup.key ?? embed?.key;
    const closeEmbed = () => { setEmbed(null); issue.reset(); };

    return (
        <>
            {key ? (
                <div className="grid gap-3">
                    <h3 className="text-base font-medium" role="status" data-testid="widget-setup-embed-status">{t(key.is_active ? 'Snippet ready' : 'Widget key revoked')}</h3>
                    {key.is_active && (
                        <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                            <li>{t('Install the snippet on an allowed origin and ask a question covered by the uploaded documents. Open the cited source.')}</li>
                            {key.user_auth_enabled && <li>{t('Configure the host token endpoint, sign in to the host application, and verify that the widget opens for that user.')}</li>}
                        </ul>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {key.is_active && <Button variant="primary" data-testid="widget-setup-open-snippet" onClick={() => setEmbed({ key })}>{t('Open embed snippet')}</Button>}
                        <Button data-testid="widget-setup-manage-keys" onClick={onManageKeys}>{t('Manage widget keys')}</Button>
                    </div>
                </div>
            ) : (
                <form className="grid gap-4" onSubmit={(event) => { event.preventDefault(); if (ready && !issue.isPending) issue.mutate(); }}>
                    <fieldset className="grid gap-3" disabled={issue.isPending}>
                        <label className="grid gap-1 text-sm">
                            {t('Key label')}
                            <Input value={label} required maxLength={120} data-testid="widget-setup-key-label" onChange={(event) => setLabel(event.target.value)} />
                        </label>
                        <label className="grid gap-1 text-sm">
                            {t('Allowed origins')}
                            <Textarea required rows={3} placeholder="https://example.com" value={origins} data-testid="widget-setup-origins" onChange={(event) => setOrigins(event.target.value)} />
                            <span className="text-xs text-muted-foreground">{t('Enter one origin per line, including the scheme and port when required.')}</span>
                        </label>
                        <label className="grid gap-1 text-sm">
                            {t('Widget layout')}
                            <select className={selectClass} value={mode} data-testid="widget-setup-mode" onChange={(event) => setMode(event.target.value as WidgetMode)}>
                                <option value="helper">{t('Floating launcher')}</option>
                                <option value="inline">{t('Inline chat')}</option>
                                <option value="fullscreen">{t('Fullscreen')}</option>
                            </select>
                        </label>
                        {setup.failures.length > 0 && (
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" required checked={acceptPartial} data-testid="widget-setup-accept-partial" onChange={(event) => setAcceptPartial(event.target.checked)} />
                                {t('Continue with the indexed documents despite the file errors.')}
                            </label>
                        )}
                    </fieldset>
                    {setup.user_auth_enabled && <p className="text-sm text-muted-foreground">{t('Copy the identity credential to the host server secret manager when it is shown. It is displayed once.')}</p>}
                    {issue.isError && <p role="alert" className="text-sm text-destructive">{toAdminError(issue.error).message}</p>}
                    <div className="flex flex-wrap justify-between gap-2">
                        <Button disabled={issue.isPending} onClick={onBack} data-testid="widget-setup-back-documents">{t('Back to documents')}</Button>
                        <Button variant="primary" type="submit" busy={issue.isPending} disabled={!ready || (setup.failures.length > 0 && !acceptPartial)} data-testid="widget-setup-issue-key">{t('Create key and show snippet')}</Button>
                    </div>
                </form>
            )}
            {key && <div><Button variant="quiet" size="sm" onClick={onBack} data-testid="widget-setup-view-documents">{t('Back to documents')}</Button></div>}
            {embed && (
                <EmbedCodeDialog
                    open
                    onOpenChange={(open) => { if (!open) closeEmbed(); }}
                    publicKey={embed.key.public_key}
                    projectKey={embed.key.project_key}
                    label={embed.key.label}
                    apiBase={window.location.origin}
                    allowedOrigins={embed.key.allowed_origins}
                    userAuthEnabled={embed.key.user_auth_enabled}
                    identitySecret={embed.identitySecret}
                    initialTab={embed.key.user_auth_enabled ? 'authenticated' : 'quickstart'}
                    theme={embed.key.theme}
                    intro={embed.key.intro ?? undefined}
                />
            )}
        </>
    );
}
