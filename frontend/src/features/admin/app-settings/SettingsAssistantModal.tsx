import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { ArrowRight, Check, Sparkles, X } from 'lucide-react';
import { Button } from '../../../components/Button';
import { Textarea } from '../../../components/ui/textarea';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../../../components/ui/dialog';
import { useUiTranslation } from '../../../lib/ui-locale';
import { flattenLaravelError, parseLaravelError } from '../../../lib/laravel-errors';
import { adminAppSettingsApi, type AppSettingDto, type SettingsDraft } from './app-settings.api';

interface SettingsAssistantModalProps {
    target: 'configuration' | 'agent.tenant_instructions';
    tenant: string | null;
    scope: string;
    provider: string;
    draft: SettingsDraft;
    settings: AppSettingDto[];
    onApply: (values: SettingsDraft) => void;
    onClose: () => void;
}

export function SettingsAssistantModal({ target, tenant, scope, provider, draft, settings, onApply, onClose }: SettingsAssistantModalProps) {
    const { t } = useUiTranslation();
    const [prompt, setPrompt] = useState('');
    const generation = useMutation({ mutationFn: () => adminAppSettingsApi.assist(prompt, target, draft, provider, scope, tenant) });
    const proposal = generation.data;
    const labels = Object.fromEntries(settings.map((setting) => [setting.key, setting.label]));
    const error = generation.isError ? flattenLaravelError(parseLaravelError(generation.error)) : null;

    return <Dialog open onOpenChange={(open) => { if (!open) onClose(); }}>
        <DialogContent className="settings-assistant-dialog" data-testid="settings-assistant-modal" showCloseButton={false}>
            <DialogHeader className="settings-assistant-header">
                <span className="settings-assistant-mark"><Sparkles size={23} aria-hidden /></span>
                <div><DialogTitle>{t(target === 'configuration' ? 'Configure with the agent' : 'Write operating instructions')}</DialogTitle>
                    <DialogDescription>{t('Describe the goal. Review the proposal, then fill the form. Nothing is saved automatically.')}</DialogDescription></div>
                <DialogClose asChild><Button variant="quiet" size="sm" iconOnly aria-label={t('Close configuration assistant')}><X size={16} aria-hidden /></Button></DialogClose>
            </DialogHeader>
            <div className="settings-assistant-body">
                <div className="settings-assistant-steps" aria-label={t('Assisted form steps')}>
                    <span data-current={!proposal}><b>1</b>{t('Describe')}</span><ArrowRight size={14} aria-hidden />
                    <span data-current={!!proposal}><b>2</b>{t('Review')}</span><ArrowRight size={14} aria-hidden />
                    <span><b>3</b>{t('Fill the form')}</span>
                </div>
                <label htmlFor="settings-assistant-prompt" className="settings-field-label">{t('What would you like to configure?')}</label>
                <Textarea id="settings-assistant-prompt" value={prompt} maxLength={4000} rows={5} disabled={generation.isPending}
                    onChange={(event) => setPrompt(event.target.value)}
                    placeholder={t(target === 'configuration' ? 'For example: use an economical model for order support, keep answers grounded in sources, and synchronize every 30 minutes.'
                        : 'Describe the agent’s business task, the sources it should use, and how it should advise your operators.')} />
                <div className="settings-assistant-prompt-footer"><span>{t('Configuration scope')}: {scope === '*' ? t('Entire organization') : scope}</span>
                    <Button variant="secondary" size="sm" leadingIcon={<Sparkles size={14} aria-hidden />} busy={generation.isPending}
                        disabled={prompt.trim().length < 5} onClick={() => generation.mutate()}>{t(proposal ? 'Generate another proposal' : 'Generate proposal')}</Button></div>
                {error && <div role="alert" className="settings-alert">{error}</div>}
                {proposal && <section className="settings-assistant-proposal" aria-label={t('Proposed changes')}>
                    <h3><Check size={17} aria-hidden />{t('Proposal ready to review')}</h3><p>{proposal.summary}</p>
                    {proposal.changes.map((change) => <div key={change.key} className="settings-assistant-change">
                        <h4>{t(labels[change.key] ?? change.key)}</h4>
                        {change.reason && <p>{change.reason}</p>}
                        <pre>{change.value === null ? t('Inherit the default') : String(change.value)}</pre>
                    </div>)}
                    <small>{t('Generated with')} {proposal.provider} · {proposal.model}</small>
                </section>}
            </div>
            <footer className="settings-assistant-footer"><span>{t('You can edit the filled values before saving.')}</span>
                <Button variant="primary" size="md" leadingIcon={<ArrowRight size={15} aria-hidden />} disabled={!proposal || generation.isPending}
                    onClick={() => { if (proposal) onApply(Object.fromEntries(proposal.changes.map((change) => [change.key, change.value]))); }}>
                    {t('Fill the form')}</Button></footer>
        </DialogContent>
    </Dialog>;
}
