import { Cpu, LockKeyhole, Plug, RotateCcw, ShieldCheck, Sparkles } from 'lucide-react';
import { Button } from '../../../components/Button';
import { Input } from '../../../components/ui/input';
import { Textarea } from '../../../components/ui/textarea';
import { SelectField, SelectOption } from '../../../components/ui/select-field';
import { useUiTranslation } from '../../../lib/ui-locale';
import { ModelPicker } from './ModelPicker';
import type { AiModelCatalog, AppSettingDto, SettingValue } from './app-settings.api';

export const MODEL_KEYS = ['ai.chat_model', 'agent.synthesis.model', 'agent.synthesis.repair_model'];
export const PROVIDER_NAMES: Record<string, string> = { openai: 'OpenAI', anthropic: 'Anthropic', gemini: 'Google Gemini', openrouter: 'OpenRouter', regolo: 'Regolo' };
export const GROUPS = [
    { id: 'instructions', title: 'Agent behavior', description: 'Give the agent a clear operating brief.', icon: Sparkles, keys: ['agent.tenant_instructions'] },
    { id: 'models', title: 'Provider and models', description: 'Choose available models and compare current list prices.', icon: Cpu, keys: ['ai.provider', 'ai.chat_model', 'ai.chat_routing', 'agent.synthesis.model', 'agent.synthesis.repair_model'] },
    { id: 'operations', title: 'Integrations and execution', description: 'Set synchronization cadence and runtime behavior.', icon: Plug, keys: ['connector.sync_cadence_minutes', 'connector.mcp.runtime_mode', 'agent.planner.mode'] },
    { id: 'governance', title: 'Installation controls', description: 'Read-only controls managed outside this form.', icon: ShieldCheck, keys: ['ai_finops.enabled'] },
];
const HELP: Record<string, [string, string]> = {
    'agent.tenant_instructions': ['Define the agent’s business task, sources, tone, and operating rules.', 'Used by interpretation, planning, and answers for every project in this organization. Source and permission checks always remain active.'],
    'ai.provider': ['The service that runs your text models.', 'Changing provider resets the model overrides in this form. Choose compatible models before saving. Credentials are managed separately.'],
    'ai.chat_model': ['One model for interpretation, planning, generation, and repair.', 'When set, this model takes priority over the stage-specific models below. Leave inherited to use the individual stage defaults.'],
    'ai.chat_routing': ['Choose how OpenRouter routes requests to its providers.', 'Economy sorts by price and enforces the application’s configured price caps. It is available only with OpenRouter.'],
    'agent.synthesis.model': ['Writes the final answer from the verified evidence.', 'Used when no shared text model is selected. An inherited value uses the provider’s configured default.'],
    'agent.synthesis.repair_model': ['Repairs answers that fail the first validation.', 'An inherited value reuses the answer model. It cannot relax source checks or the validation threshold.'],
    'connector.sync_cadence_minutes': ['How often connectors check for new or changed content.', 'Enter a whole number between 5 and 1,440 minutes. A project can use its own cadence.'],
    'connector.mcp.runtime_mode': ['Controls the rollout of the MCP connector runtime.', 'Off disables it, shadow observes it, and active enables it. The installation’s master switch still applies.'],
    'agent.planner.mode': ['Choose the agent planning engine for this scope.', 'Classic uses the established planner. Shadow compares the capability planner; capability activates it.'],
    'ai_finops.enabled': ['Tracks AI usage and costs for the installation.', 'This setting is managed by the installation and is shown here for reference.'],
};

interface SettingFieldProps {
    setting: AppSettingDto;
    value: SettingValue;
    scope: string;
    provider: string;
    sharedModel: boolean;
    catalog?: AiModelCatalog;
    loading: boolean;
    busy: boolean;
    onChange: (value: SettingValue) => void;
    onAssist: () => void;
}

export function SettingField({ setting, value, scope, provider, sharedModel, catalog, loading, busy, onChange, onAssist }: SettingFieldProps) {
    const { t } = useUiTranslation();
    const id = `app-setting-${setting.key}`;
    const helpId = `${id}-help`;
    const readOnly = setting.deploy_only || (scope !== '*' && setting.scope !== 'both');
    const instructions = setting.key === 'agent.tenant_instructions';
    const model = MODEL_KEYS.includes(setting.key);
    const price = catalog?.models.find((item) => item.id === value);
    const info = HELP[setting.key];
    const override = scope === '*' ? setting.source === 'tenant' : setting.source === 'project';
    return <div className={`settings-field${instructions ? ' settings-field-instructions' : ''}`} data-testid={`app-setting-row-${setting.key}`} data-source={setting.source}>
        <div className="settings-field-description"><label htmlFor={id} className="settings-field-label">{t(setting.label)}</label>
            <p>{t(info?.[0] ?? setting.label)}</p><div className="settings-field-badges">
                <span className="settings-source-badge" data-source={setting.source} data-testid={`${id}-source`}>{t(setting.source === 'project' ? 'project override' : setting.source === 'tenant' ? 'tenant override' : 'config default')}</span>
                {readOnly && <span className="settings-readonly-badge" data-testid={`${id}-${setting.deploy_only ? 'deploy-only' : 'tenant-only'}`}><LockKeyhole size={11} aria-hidden />{t(setting.deploy_only ? 'deploy-managed' : 'tenant-wide only')}</span>}
                {setting.key.startsWith('agent.synthesis.') && sharedModel && <span className="settings-source-badge">{t('Shared model takes priority')}</span>}
            </div></div>
        <div className="settings-field-control">
            {instructions ? <>
                {!readOnly && <div className="settings-instructions-toolbar"><span>{t('Operating brief')}</span><Button variant="secondary" size="sm" leadingIcon={<Sparkles size={14} aria-hidden />} disabled={busy} onClick={onAssist}>{t('Write with the agent')}</Button></div>}
                <Textarea id={id} data-testid={`${id}-input`} aria-describedby={helpId} value={String(value ?? '')} readOnly={readOnly} disabled={busy}
                    onChange={(event) => onChange(event.target.value)} rows={12} maxLength={16000} placeholder={t('Describe the agent’s task, authorized sources, and the way it should help your team.')} />
                <div className="settings-editor-meta"><code>{setting.key}</code><span>{String(value ?? '').length.toLocaleString()} / 16.000</span></div>
            </> : readOnly ? <div className="settings-readonly-value" data-testid={`${id}-value`}>{typeof value === 'boolean' ? t(value ? 'Enabled' : 'Disabled') : String(value ?? t('Inherited'))}</div>
                : model ? <ModelPicker id={id} label={setting.label} describedBy={helpId} value={String(value ?? '')} inheritedModel={String(setting.inherited_value ?? catalog?.default_model ?? '')}
                    catalog={catalog} loading={loading} disabled={busy} onChange={(next) => onChange(next || null)} />
                : setting.type === 'enum' || setting.type === 'bool' ? <SelectField id={id} data-testid={`${id}-input`} aria-describedby={helpId} aria-label={t(setting.label)} disabled={busy}
                    value={String(value ?? '')} onValueChange={(next) => onChange(next === '' ? null : setting.type === 'bool' ? next === 'true' : next)}>
                    <SelectOption value="">{t('Inherit the default')}{setting.inherited_value != null ? ` · ${PROVIDER_NAMES[String(setting.inherited_value)] ?? setting.inherited_value}` : ''}</SelectOption>
                    {(setting.type === 'bool' ? ['true', 'false'] : setting.enum ?? []).map((option) => <SelectOption key={option} value={option} disabled={setting.key === 'ai.chat_routing' && option === 'economy' && provider !== 'openrouter'}>{PROVIDER_NAMES[option] ?? t(option)}</SelectOption>)}
                </SelectField> : <Input id={id} data-testid={`${id}-input`} aria-describedby={helpId} aria-label={t(setting.label)} type={setting.type === 'int' ? 'number' : 'text'} min={setting.min ?? undefined} max={setting.max ?? undefined}
                    disabled={busy} value={String(value ?? '')} onChange={(event) => onChange(event.target.value)} />}
            <p id={helpId} className="settings-field-help">{t(info?.[1] ?? 'Changes take effect after you save.')}</p>
            {model && price && <p className="settings-price-source">{t('Price source')}: {price.price_source ?? t('Unknown')}{price.price_updated_at ? ` · ${new Date(price.price_updated_at).toLocaleString()}` : ''} · {price.currency}</p>}
            {!instructions && <code className="settings-technical-key">{setting.key}</code>}
            {!readOnly && override && <Button variant="quiet" size="sm" leadingIcon={<RotateCcw size={12} aria-hidden />} disabled={busy} data-testid={`${id}-clear`} onClick={() => onChange(null)}>{t('Remove this override')}</Button>}
        </div>
    </div>;
}
