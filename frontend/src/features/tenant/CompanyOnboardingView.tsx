import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
    completeCompanyOnboarding,
    me,
    type CompanyOnboardingInput,
} from '../auth/auth.api';
import { extractAxiosErrors } from '../auth/auth-errors';
import { FieldError, type FieldErrors } from '../auth/AuthLayout';
import { useAuthStore } from '../../lib/auth-store';

const optionalSlug = z
    .string()
    .regex(/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/, 'Use lowercase words separated by hyphens or underscores')
    .or(z.literal(''));

const schema = z.object({
    company_name: z.string().trim().min(1, 'Please enter your company name').max(200),
    tenant_slug: optionalSlug,
    project_key: optionalSlug,
});

type FormValues = z.infer<typeof schema>;

export type CompanyOnboardingViewProps = {
    onSuccess?: () => void;
};

export function CompanyOnboardingView({ onSuccess }: CompanyOnboardingViewProps = {}) {
    const setMe = useAuthStore((state) => state.setMe);
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>();
    const [formError, setFormError] = useState<string>();
    const [submitting, setSubmitting] = useState(false);
    const {
        register,
        handleSubmit,
        formState: { errors },
    } = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            company_name: '',
            tenant_slug: '',
            project_key: '',
        },
    });

    const submit = handleSubmit(async (values) => {
        setFieldErrors(undefined);
        setFormError(undefined);
        setSubmitting(true);

        const input: CompanyOnboardingInput = {
            company_name: values.company_name.trim(),
        };
        if (values.tenant_slug !== '') input.tenant_slug = values.tenant_slug;
        if (values.project_key !== '') input.project_key = values.project_key;

        try {
            await completeCompanyOnboarding(input);
            const payload = await me();
            setMe(payload);
            onSuccess?.();
        } catch (error) {
            const result = extractAxiosErrors(
                error,
                'Company creation failed. Check the details and try again.',
            );
            setFieldErrors(result.fieldErrors);
            setFormError(result.message);
        } finally {
            setSubmitting(false);
        }
    });

    const labelStyle = {
        color: 'var(--fg-2)',
        display: 'block',
        fontSize: 12,
        marginBottom: 6,
    } as const;

    return (
        <section
            aria-labelledby="company-onboarding-title"
            data-testid="company-onboarding-view"
            data-state={submitting ? 'loading' : formError || fieldErrors ? 'error' : 'ready'}
            aria-busy={submitting}
            style={{
                alignItems: 'center',
                display: 'flex',
                flex: 1,
                justifyContent: 'center',
                padding: 32,
            }}
        >
            <div
                className="panel popin"
                style={{
                    background: 'var(--panel-solid)',
                    maxWidth: 520,
                    padding: 32,
                    width: '100%',
                }}
            >
                <h1 id="company-onboarding-title" style={{ fontSize: 22, margin: '0 0 8px' }}>
                    Crea la tua azienda
                </h1>
                <p style={{ color: 'var(--fg-3)', lineHeight: 1.6, margin: '0 0 22px' }}>
                    Il tuo account è pronto, ma non appartiene ancora a un’azienda.
                    Completa questo passaggio per entrare in AskMyDocs come Super Admin.
                </p>

                <form
                    aria-label="Create company"
                    data-testid="company-onboarding-form"
                    onSubmit={submit}
                    noValidate
                    style={{ display: 'flex', flexDirection: 'column', gap: 16 }}
                >
                    {formError && (
                        <div
                            data-testid="company-onboarding-error"
                            role="alert"
                            aria-live="assertive"
                            style={{
                                background: 'rgba(239,68,68,.12)',
                                border: '1px solid rgba(239,68,68,.3)',
                                borderRadius: 9,
                                color: 'var(--err)',
                                fontSize: 13,
                                padding: '10px 12px',
                            }}
                        >
                            {formError}
                        </div>
                    )}

                    <div>
                        <label htmlFor="company_name" style={labelStyle}>Nome azienda</label>
                        <input
                            id="company_name"
                            className="input"
                            data-testid="company-onboarding-name"
                            autoComplete="organization"
                            {...register('company_name')}
                        />
                        <FieldError
                            errors={errors.company_name
                                ? { company_name: errors.company_name.message ?? '' }
                                : fieldErrors}
                            name="company_name"
                        />
                    </div>

                    <div>
                        <label htmlFor="tenant_slug" style={labelStyle}>Identificativo azienda (opzionale)</label>
                        <input
                            id="tenant_slug"
                            className="input"
                            data-testid="company-onboarding-slug"
                            autoComplete="off"
                            placeholder="generato dal nome se vuoto"
                            {...register('tenant_slug')}
                        />
                        <FieldError
                            errors={errors.tenant_slug
                                ? { tenant_slug: errors.tenant_slug.message ?? '' }
                                : fieldErrors}
                            name="tenant_slug"
                        />
                        <FieldError errors={fieldErrors} name="slug" />
                    </div>

                    <div>
                        <label htmlFor="project_key" style={labelStyle}>Progetto iniziale (opzionale)</label>
                        <input
                            id="project_key"
                            className="input"
                            data-testid="company-onboarding-project"
                            autoComplete="off"
                            placeholder="uguale all’identificativo se vuoto"
                            {...register('project_key')}
                        />
                        <FieldError
                            errors={errors.project_key
                                ? { project_key: errors.project_key.message ?? '' }
                                : fieldErrors}
                            name="project_key"
                        />
                    </div>

                    <button
                        type="submit"
                        className="btn primary"
                        data-testid="company-onboarding-submit"
                        disabled={submitting}
                        aria-busy={submitting}
                        style={{ justifyContent: 'center', marginTop: 4 }}
                    >
                        {submitting ? 'Creazione in corso…' : 'Crea azienda e continua'}
                    </button>
                </form>
            </div>
        </section>
    );
}
