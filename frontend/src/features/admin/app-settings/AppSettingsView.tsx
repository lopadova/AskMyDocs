import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Building2, Check, Info, RefreshCw, Save, Settings2, Sparkles } from 'lucide-react';
import { Button } from '../../../components/Button';
import { SelectField, SelectOption } from '../../../components/ui/select-field';
import { useUiTranslation } from '../../../lib/ui-locale';
import { useTeamStore } from '../../../lib/team-store';
import { flattenLaravelError, parseLaravelError } from '../../../lib/laravel-errors';
import { adminProjectsApi } from '../projects/admin-projects.api';
import { AdminShell } from '../shell/AdminShell';
import { useAiModelCatalog, useAppSettings, useSetAppSettings } from './app-settings-hooks';
import { WILDCARD, type AppSettingDto, type SettingsDraft, type SettingValue } from './app-settings.api';
import { GROUPS, MODEL_KEYS, PROVIDER_NAMES, SettingField } from './settings-fields';
import { SettingsAssistantModal } from './SettingsAssistantModal';
import './app-settings.css';

const normalized = (value: SettingValue): SettingValue => typeof value === 'string' ? value.trim() || null : value;

export function AppSettingsView() {
    const tenant = useTeamStore((state) => state.currentTeam);
    return <OrganizationSettingsView key={tenant ?? 'none'} />;
}

function OrganizationSettingsView() {
    const { t } = useUiTranslation();
    const tenant = useTeamStore((state) => state.currentTeam);
    const team = useTeamStore((state) => state.teams.find((item) => item.tenant_id === state.currentTeam));
    const [scope, setScope] = useState(WILDCARD);
    const [saving, setSaving] = useState(false);
    const query = useAppSettings(scope);
    const projects = useQuery({ queryKey: ['settings-projects', tenant], queryFn: () => adminProjectsApi.list(tenant), staleTime: 60_000, retry: false });
    const state = query.isLoading ? 'loading' : query.isError ? 'error' : (query.data?.length ?? 0) === 0 ? 'empty' : 'ready';
    return <AdminShell section="app-settings"><div className="app-settings-page" data-testid="admin-app-settings" data-state={state} aria-busy={query.isLoading}>
        <div className="settings-page-heading"><span className="settings-eyebrow"><Settings2 size={14} aria-hidden />{t('System settings')}</span><h1>{t('Configuration')}</h1>
            <p>{t('Shape your agent’s behavior, choose its models, and keep integrations running smoothly.')}</p></div>
        <section className="settings-scope-panel" aria-label={t('Configuration scope')}>
            <div className="settings-organization"><span><Building2 size={22} aria-hidden /></span><div><small>{t('Organization')}</small><strong>{team?.name ?? t('Current organization')}</strong></div></div>
            <div className="settings-scope-field"><label htmlFor="app-settings-scope">{t('Apply settings to')}</label>
                <SelectField id="app-settings-scope" data-testid="app-settings-scope" value={scope} onValueChange={setScope} disabled={saving}>
                    <SelectOption value={WILDCARD}>{t('Entire organization')}</SelectOption>
                    {(projects.data ?? []).map((project) => <SelectOption key={project.project_key} value={project.project_key}>{project.name}</SelectOption>)}
                </SelectField><p>{t(scope === WILDCARD ? 'Projects inherit these values unless they have an override.' : 'Only this project changes. Organization-wide fields remain read-only.')}</p>
                {projects.isError && <p role="status">{t('Project choices could not be loaded.')} <Button variant="quiet" size="sm" onClick={() => void projects.refetch()}>{t('Retry')}</Button></p>}
            </div>
        </section>
        {state === 'loading' && <div className="settings-state" role="status" data-testid="admin-app-settings-loading">{t('Loading settings…')}</div>}
        {state === 'error' && <div className="settings-alert" role="alert" data-testid="admin-app-settings-error">{t('Could not load settings.')} <Button variant="secondary" size="sm" data-testid="admin-app-settings-retry" onClick={() => void query.refetch()}>{t('Retry')}</Button></div>}
        {state === 'empty' && <div className="settings-state" data-testid="admin-app-settings-empty">{t('No governable settings are registered.')}</div>}
        {state === 'ready' && <SettingsForm key={`${tenant}:${scope}`} tenant={tenant} scope={scope} settings={query.data ?? []} onSaving={setSaving} />}
    </div></AdminShell>;
}

function SettingsForm({ tenant, scope, settings, onSaving }: { tenant: string | null; scope: string; settings: AppSettingDto[]; onSaving: (value: boolean) => void }) {
    const { t } = useUiTranslation();
    const [draft, setDraft] = useState<SettingsDraft>({});
    const [assistant, setAssistant] = useState<'configuration' | 'agent.tenant_instructions' | null>(null);
    const [notice, setNotice] = useState('');
    const mutation = useSetAppSettings(scope);
    const byKey = Object.fromEntries(settings.map((setting) => [setting.key, setting]));
    const value = (key: string): SettingValue => Object.prototype.hasOwnProperty.call(draft, key) ? draft[key] : byKey[key]?.value ?? null;
    const provider = String(value('ai.provider') ?? byKey['ai.provider']?.inherited_value ?? 'openai');
    const catalog = useAiModelCatalog(provider);
    const changes = Object.fromEntries(Object.entries(draft).filter(([key, next]) => normalized(next) !== normalized(byKey[key]?.value ?? null)).map(([key, next]) => [key, normalized(next)]));
    const dirty = Object.keys(changes).length > 0;
    const invalidModel = MODEL_KEYS.some((key) => typeof changes[key] === 'string' && changes[key] !== '' && (!catalog.data || catalog.data.stale || !catalog.data.models.some((model) => model.id === changes[key] && model.selectable)));
    const error = mutation.isError ? flattenLaravelError(parseLaravelError(mutation.error)) : null;
    const update = (key: string, next: SettingValue) => {
        mutation.reset(); setNotice('');
        setDraft((current) => {
            const updated = { ...current, [key]: next };
            if (key === 'ai.provider' && next !== value('ai.provider')) {
                for (const modelKey of MODEL_KEYS) if (byKey[modelKey]) updated[modelKey] = null;
                if (byKey['ai.chat_routing']) updated['ai.chat_routing'] = 'default';
            }
            return updated;
        });
    };
    const save = async () => {
        onSaving(true);
        try { await mutation.mutateAsync(changes); setDraft({}); setNotice('Configuration saved.'); }
        finally { onSaving(false); }
    };
    return <>
        <div className="settings-workbar"><div><strong>{t(dirty ? 'Changes ready to save' : 'Your current configuration')}</strong><span>{dirty ? Object.keys(changes).length === 1 ? t('One modified field') : t('{count} modified fields', { count: Object.keys(changes).length }) : t('Changes take effect after you save.')}</span></div>
            <div className="settings-actions">{dirty && <Button variant="quiet" size="sm" disabled={mutation.isPending} onClick={() => { setDraft({}); mutation.reset(); setNotice(''); }}>{t('Discard changes')}</Button>}
                <Button variant="secondary" size="md" leadingIcon={<Sparkles size={15} aria-hidden />} disabled={mutation.isPending} onClick={() => setAssistant('configuration')}>{t('Configure with the agent')}</Button>
                <Button variant="primary" size="md" leadingIcon={<Save size={15} aria-hidden />} disabled={!dirty || invalidModel} busy={mutation.isPending} data-testid="app-settings-save" onClick={() => void save().catch(() => undefined)}>{t('Save changes')}</Button></div>
        </div>
        {notice && <div role="status" className="settings-notice"><Check size={16} aria-hidden />{t(notice)}</div>}
        {error && <div role="alert" className="settings-alert">{error}</div>}
        <div data-testid="admin-app-settings-form" className="settings-sections">{GROUPS.map((group) => {
            const rows = group.keys.map((key) => byKey[key]).filter(Boolean);
            if (!rows.length) return null;
            const GroupIcon = group.icon;
            return <section key={group.id} className="settings-section" id={`settings-${group.id}`} aria-labelledby={`settings-${group.id}-title`}>
                <header className="settings-section-header"><span className="settings-section-icon"><GroupIcon size={21} aria-hidden /></span><div><h2 id={`settings-${group.id}-title`}>{t(group.title)}</h2><p>{t(group.description)}</p></div>
                    {group.id === 'models' && <Button variant="quiet" size="sm" leadingIcon={<RefreshCw size={14} aria-hidden />} busy={catalog.refresh.isPending} onClick={() => catalog.refresh.mutate()}>{t('Refresh catalog')}</Button>}</header>
                {group.id === 'models' && <div className="settings-catalog-status" role="status"><Info size={15} aria-hidden /><div><strong>{PROVIDER_NAMES[provider] ?? provider}</strong>{' · '}
                    {catalog.isLoading ? t('Loading available models and prices…') : catalog.data?.error === 'missing_credentials' ? t('Configure this provider’s credentials to load its available models.') : catalog.data?.error === 'credentials_rejected' ? t('The provider rejected its configured credentials. Update the API key in the installation settings.') : catalog.isError || catalog.data?.stale || catalog.refresh.isError ? t('The catalog could not be refreshed. Previous data may be outdated.') : t('{count} available models', { count: catalog.data?.models.length ?? 0 })}
                    {catalog.data?.updated_at && <small>{t('Catalog checked')}: {new Date(catalog.data.updated_at).toLocaleString()} · {t('Prices per million tokens; source and currency are shown for each model.')}</small>}
                    {catalog.data && !catalog.data.configured && catalog.data.error !== 'missing_credentials' && <small>{t('The public catalog is available, but API credentials are not configured for this provider.')}</small>}</div></div>}
                {rows.map((setting) => <SettingField key={setting.key} setting={setting} scope={scope} provider={provider} value={value(setting.key)}
                    sharedModel={!!value('ai.chat_model')} catalog={catalog.data} loading={catalog.isFetching} busy={mutation.isPending}
                    onChange={(next) => update(setting.key, next)} onAssist={() => setAssistant('agent.tenant_instructions')} />)}
            </section>;
        })}</div>
        {assistant && <SettingsAssistantModal target={assistant} tenant={tenant} scope={scope} provider={provider} settings={settings} draft={Object.fromEntries(settings.map((setting) => [setting.key, value(setting.key)]))}
            onClose={() => setAssistant(null)} onApply={(values) => { setDraft((current) => ({ ...current, ...values })); setAssistant(null); mutation.reset(); setNotice('Proposal filled in. Review the values and save when ready.'); }} />}
    </>;
}
