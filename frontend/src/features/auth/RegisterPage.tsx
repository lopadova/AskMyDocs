import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import axios from 'axios';
import { AuthLayout, FieldError, type FieldErrors } from './AuthLayout';
import { register as registerApi, me } from './auth.api';
import { useAuthStore } from '../../lib/auth-store';

const schema = z
    .object({
        name: z.string().min(1, 'Your name is required'),
        email: z.string().email('Please enter a valid email address'),
        password: z.string().min(8, 'Password must be at least 8 characters'),
        password_confirmation: z.string().min(1, 'Please confirm your password'),
        code: z.string().min(1, 'An invite code is required to register'),
    })
    .refine((v) => v.password === v.password_confirmation, {
        path: ['password_confirmation'],
        message: 'Passwords do not match',
    });

type FormValues = z.infer<typeof schema>;

export type RegisterPageProps = {
    onSuccess?: () => void;
    onNavigateLogin?: () => void;
};

function extractAxiosErrors(err: unknown): { fieldErrors?: FieldErrors; message?: string } {
    if (!axios.isAxiosError(err)) {
        return { message: 'Something went wrong. Please try again.' };
    }
    const status = err.response?.status;
    const data = err.response?.data as { message?: string; errors?: FieldErrors } | undefined;
    // 422 (validation) AND the invite-code failures (409 exhausted / 410
    // expired-revoked / 403 ineligible) all carry an `errors.code` field, so
    // surface them next to the matching input.
    if (data?.errors) {
        return { fieldErrors: data.errors };
    }
    if (status === 429) {
        return { message: data?.message ?? 'Too many attempts — please try again in a moment.' };
    }
    return { message: data?.message ?? 'Registration failed. Please try again.' };
}

export function RegisterPage({ onSuccess, onNavigateLogin }: RegisterPageProps = {}) {
    const setMe = useAuthStore((s) => s.setMe);
    const [fieldErrors, setFieldErrors] = useState<FieldErrors | undefined>();
    const [formError, setFormError] = useState<string | undefined>();
    const [submitting, setSubmitting] = useState(false);

    const {
        register,
        handleSubmit,
        formState: { errors },
    } = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', email: '', password: '', password_confirmation: '', code: '' },
    });

    const onSubmit = handleSubmit(async (values) => {
        setFieldErrors(undefined);
        setFormError(undefined);
        setSubmitting(true);
        try {
            await registerApi({
                name: values.name,
                email: values.email,
                password: values.password,
                password_confirmation: values.password_confirmation,
                code: values.code,
            });
            const mePayload = await me();
            setMe(mePayload);
            onSuccess?.();
            return;
        } catch (err) {
            const { fieldErrors: fe, message } = extractAxiosErrors(err);
            setFieldErrors(fe);
            setFormError(message);
        } finally {
            setSubmitting(false);
        }
    });

    const labelStyle = { fontSize: 12, color: 'var(--fg-2)', display: 'block', marginBottom: 6 } as const;

    return (
        <AuthLayout
            title="Create your account"
            subtitle="Registration is invite-only — enter the code you were given."
            footer={
                <span>
                    Already have an account?{' '}
                    <button
                        type="button"
                        data-testid="register-to-login"
                        onClick={onNavigateLogin}
                        style={{ background: 'transparent', border: 0, color: 'var(--fg-1)', cursor: 'pointer', padding: 0, fontWeight: 500 }}
                    >
                        Sign in
                    </button>
                </span>
            }
        >
            <form
                onSubmit={onSubmit}
                noValidate
                data-testid="register-form"
                aria-label="Create account"
                style={{ display: 'flex', flexDirection: 'column', gap: 14 }}
            >
                {formError && (
                    <div
                        role="alert"
                        data-testid="register-form-error"
                        style={{ padding: '10px 12px', borderRadius: 9, background: 'rgba(239,68,68,.12)', border: '1px solid rgba(239,68,68,.3)', color: 'var(--err)', fontSize: 13 }}
                    >
                        {formError}
                    </div>
                )}

                <div>
                    <label htmlFor="register-code" style={labelStyle}>Invite code</label>
                    <input
                        id="register-code"
                        type="text"
                        autoComplete="off"
                        className="input"
                        data-testid="register-code"
                        aria-label="Invite code"
                        {...register('code')}
                    />
                    {errors.code && <FieldError errors={{ code: errors.code.message ?? '' }} name="code" />}
                    <FieldError errors={fieldErrors} name="code" />
                </div>

                <div>
                    <label htmlFor="register-name" style={labelStyle}>Name</label>
                    <input id="register-name" type="text" autoComplete="name" className="input" data-testid="register-name" aria-label="Name" {...register('name')} />
                    {errors.name && <FieldError errors={{ name: errors.name.message ?? '' }} name="name" />}
                    <FieldError errors={fieldErrors} name="name" />
                </div>

                <div>
                    <label htmlFor="register-email" style={labelStyle}>Email</label>
                    <input id="register-email" type="email" autoComplete="email" className="input" data-testid="register-email" aria-label="Email" {...register('email')} />
                    {errors.email && <FieldError errors={{ email: errors.email.message ?? '' }} name="email" />}
                    <FieldError errors={fieldErrors} name="email" />
                </div>

                <div>
                    <label htmlFor="register-password" style={labelStyle}>Password</label>
                    <input id="register-password" type="password" autoComplete="new-password" className="input" data-testid="register-password" aria-label="Password" {...register('password')} />
                    {errors.password && <FieldError errors={{ password: errors.password.message ?? '' }} name="password" />}
                    <FieldError errors={fieldErrors} name="password" />
                </div>

                <div>
                    <label htmlFor="register-password-confirmation" style={labelStyle}>Confirm password</label>
                    <input id="register-password-confirmation" type="password" autoComplete="new-password" className="input" data-testid="register-password-confirmation" aria-label="Confirm password" {...register('password_confirmation')} />
                    {errors.password_confirmation && (
                        <FieldError errors={{ password_confirmation: errors.password_confirmation.message ?? '' }} name="password_confirmation" />
                    )}
                </div>

                <button
                    type="submit"
                    className="btn primary"
                    data-testid="register-submit"
                    disabled={submitting}
                    aria-busy={submitting}
                    style={{ justifyContent: 'center', marginTop: 4 }}
                >
                    {submitting ? 'Creating account…' : 'Create account'}
                </button>
            </form>
        </AuthLayout>
    );
}
