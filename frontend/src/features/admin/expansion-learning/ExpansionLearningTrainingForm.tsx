import { useState, type CSSProperties, type FormEvent, type ReactNode } from 'react';
import { SelectField, SelectOption } from '@/components/ui/select-field';
import { useUiTranslation } from '../../../lib/ui-locale';
import { useToast } from '../shared/Toast';
import {
    expansionLearningErrorMessage,
    useStartTraining,
    type TrainingBackend,
} from './expansion-learning.api';

/**
 * "Start training" form of the expansion learning screen.
 *
 * Training builds a dataset from the rewarded (masked) searches and either
 * exports it as JSONL or sends it to the OpenAI fine-tuning API. It is
 * super-admin only and needs an explicit confirmation: the checkbox is
 * required client-side AND the backend re-checks it (`confirm: accepted`).
 * 403 `super_admin_required` and 422 `refused` messages render next to the
 * form (R14), verbatim from the server.
 */

interface Props {
    /** The page's project filter; '' trains on every project of the tenant. */
    projectKey: string;
    trainingConsent: boolean;
    /** Allowed base models, from the status endpoint (R18: never a literal FE copy). */
    baseModels: string[];
}

export function ExpansionLearningTrainingForm({ projectKey, trainingConsent, baseModels }: Props): ReactNode {
    const { t } = useUiTranslation();
    const toast = useToast();
    const train = useStartTraining();
    const [backend, setBackend] = useState<TrainingBackend>('export');
    const [baseModel, setBaseModel] = useState<string>(baseModels[0] ?? '');
    const [confirmed, setConfirmed] = useState(false);
    const [confirmError, setConfirmError] = useState<string | null>(null);
    const [submitError, setSubmitError] = useState<string | null>(null);

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();
        setSubmitError(null);
        if (!confirmed) {
            setConfirmError(t('Confirm that you want to start a training run.'));
            return;
        }
        setConfirmError(null);
        train.mutate(
            {
                backend,
                confirm: true,
                project_key: projectKey === '' ? undefined : projectKey,
                base_model: backend === 'openai' ? baseModel : undefined,
            },
            {
                onSuccess: () => {
                    toast.success(t('Training run started.'), 'admin-expansion-learning-train-started');
                    setConfirmed(false);
                },
                onError: (err) => setSubmitError(expansionLearningErrorMessage(err)),
            },
        );
    };

    return (
        <section aria-labelledby="admin-expansion-learning-train-heading" style={{ marginBottom: 20 }}>
            <h2 id="admin-expansion-learning-train-heading" style={{ margin: '0 0 8px', fontSize: 14, color: 'var(--fg-0)' }}>
                {t('Start training')}
            </h2>
            <form
                data-testid="admin-expansion-learning-train-form"
                data-backend={backend}
                onSubmit={handleSubmit}
                noValidate
                style={{ border: '1px solid var(--panel-border, rgba(255,255,255,.1))', borderRadius: 8, padding: 14, display: 'grid', gap: 10, maxWidth: 560 }}
            >
                <p style={{ margin: 0, color: 'var(--fg-3)', fontSize: 11.5 }}>
                    {projectKey === ''
                        ? t('Uses the rewarded searches of every project. Super-admin only.')
                        : t('Uses the rewarded searches of project {project}. Super-admin only.', { project: projectKey })}
                </p>

                <div style={fieldStyle}>
                    <label htmlFor="admin-expansion-learning-train-backend" style={labelStyle}>
                        {t('Backend')}
                    </label>
                    <SelectField
                        id="admin-expansion-learning-train-backend"
                        data-testid="admin-expansion-learning-train-backend"
                        value={backend}
                        onValueChange={(value) => setBackend(value === 'openai' ? 'openai' : 'export')}
                        style={selectStyle}
                    >
                        <SelectOption value="export">{t('Export dataset (JSONL)')}</SelectOption>
                        <SelectOption value="openai">{t('OpenAI fine-tuning')}</SelectOption>
                    </SelectField>
                </div>

                {backend === 'openai' && (
                    <div style={fieldStyle}>
                        <label htmlFor="admin-expansion-learning-train-base-model" style={labelStyle}>
                            {t('Base model')}
                        </label>
                        <SelectField
                            id="admin-expansion-learning-train-base-model"
                            data-testid="admin-expansion-learning-train-base-model"
                            value={baseModel}
                            onValueChange={setBaseModel}
                            style={selectStyle}
                        >
                            {baseModels.map((model) => (
                                <SelectOption key={model} value={model}>
                                    {model}
                                </SelectOption>
                            ))}
                        </SelectField>
                        {!trainingConsent && (
                            <p data-testid="admin-expansion-learning-train-consent-hint" role="note" style={{ margin: 0, color: 'var(--warn, #b8860b)', fontSize: 11.5 }}>
                                {t('This organisation has not consented to sending its masked queries to a training provider; the request will be refused.')}
                            </p>
                        )}
                    </div>
                )}

                <div>
                    <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 12, color: 'var(--fg-1)' }}>
                        <input
                            type="checkbox"
                            data-testid="admin-expansion-learning-train-confirm"
                            checked={confirmed}
                            aria-invalid={confirmError ? 'true' : 'false'}
                            aria-describedby={confirmError ? 'admin-expansion-learning-train-confirm-error' : undefined}
                            onChange={(event) => {
                                setConfirmed(event.target.checked);
                                if (event.target.checked) setConfirmError(null);
                            }}
                        />
                        <span>{t('I understand this builds a dataset from recorded searches and, for OpenAI, sends it to the provider.')}</span>
                    </label>
                    {confirmError && (
                        <p
                            id="admin-expansion-learning-train-confirm-error"
                            data-testid="admin-expansion-learning-train-confirm-error"
                            role="alert"
                            style={errorStyle}
                        >
                            {confirmError}
                        </p>
                    )}
                </div>

                {submitError && (
                    <p data-testid="admin-expansion-learning-train-error" role="alert" style={errorStyle}>
                        {submitError}
                    </p>
                )}

                <div>
                    <button
                        type="submit"
                        data-testid="admin-expansion-learning-train-submit"
                        disabled={train.isPending}
                        aria-busy={train.isPending}
                        style={{
                            padding: '5px 12px',
                            borderRadius: 6,
                            border: '1px solid var(--accent, #6366f1)',
                            background: 'var(--accent, #6366f1)',
                            color: 'white',
                            fontSize: 12,
                            cursor: train.isPending ? 'not-allowed' : 'pointer',
                            opacity: train.isPending ? 0.6 : 1,
                        }}
                    >
                        {train.isPending ? t('Starting…') : t('Start training')}
                    </button>
                </div>
            </form>
        </section>
    );
}

const fieldStyle: CSSProperties = { display: 'grid', gap: 4 };
const labelStyle: CSSProperties = { color: 'var(--fg-2)', fontSize: 12 };
const selectStyle: CSSProperties = { fontSize: 12, minWidth: 220 };
const errorStyle: CSSProperties = { margin: '4px 0 0', color: 'var(--err)', fontSize: 12 };
