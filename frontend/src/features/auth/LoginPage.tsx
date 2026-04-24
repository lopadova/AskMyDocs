import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import axios from 'axios';
import { AuthLayout, FieldError, type FieldErrors } from './AuthLayout';
import { login, me } from './auth.api';
import { useAuthStore } from '../../lib/auth-store';

const schema = z.object({
    email: z.string().email('Please enter a valid email address'),
    password: z.string().min(1, 'Password is required'),
    remember: z.boolean().default(false),
});

type FormValues = z.infer<typeof schema>;

export type LoginPageProps = {
    onSuccess?: () => void;
    onNavigateForgot?: () => void;
};

function extractAxiosErrors(err: unknown): { fieldErrors?: FieldErrors; message?: string } {
    if (!axios.isAxiosError(err)) {
        return { message: 'Something went wrong. Please try again.' };
    }
    const status = err.response?.status;
    const data = err.response?.data as { message?: string; errors?: FieldErrors } | undefined;
    if (status === 422 && data?.errors) {
        return { fieldErrors: data.errors };
    }
    if (status === 429) {
        return { message: data?.message ?? 'Too many attempts — please try again in a moment.' };
    }
    return { message: data?.message ?? 'Login failed. Check your credentials and try again.' };
}

export function LoginPage({ onSuccess, onNavigateForgot }: LoginPageProps = {}) {
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
        defaultValues: { email: '', password: '', remember: false },
    });

    const onSubmit = handleSubmit(async (values) => {
        setFieldErrors(undefined);
        setFormError(undefined);
        setSubmitting(true);
        try {
            await login(values.email, values.password, values.remember);
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

    return (
        <AuthLayout
            title="Sign in to your workspace"
            subtitle="Use your ACME email and password."
            footer={
                <span>
                    Trouble signing in?{' '}
                    <button
                        type="button"
                        onClick={onNavigateForgot}
                        style={{
                            background: 'transparent',
                            border: 0,
                            color: 'var(--fg-1)',
                            cursor: 'pointer',
                            padding: 0,
                            fontWeight: 500,
                        }}
                    >
                        Reset your password
                    </button>
                </span>
            }
        >
            <form
                onSubmit={onSubmit}
                noValidate
                data-testid="login-form"
                aria-label="Sign in"
                style={{ display: 'flex', flexDirection: 'column', gap: 14 }}
            >
                {formError && (
                    <div
                        role="alert"
                        data-testid="login-form-error"
                        style={{
                            padding: '10px 12px',
                            borderRadius: 9,
                            background: 'rgba(239,68,68,.12)',
                            border: '1px solid rgba(239,68,68,.3)',
                            color: 'var(--err)',
                            fontSize: 13,
                        }}
                    >
                        {formError}
                    </div>
                )}
                <div>
                    <label htmlFor="email" style={{ fontSize: 12, color: 'var(--fg-2)', display: 'block', marginBottom: 6 }}>
                        Email
                    </label>
                    <input
                        id="email"
                        type="email"
                        autoComplete="email"
                        className="input"
                        data-testid="login-email"
                        aria-label="Email"
                        {...register('email')}
                    />
                    {errors.email && <FieldError errors={{ email: errors.email.message ?? '' }} name="email" />}
                    <FieldError errors={fieldErrors} name="email" />
                </div>
                <div>
                    <label
                        htmlFor="password"
                        style={{ fontSize: 12, color: 'var(--fg-2)', display: 'block', marginBottom: 6 }}
                    >
                        Password
                    </label>
                    <input
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        className="input"
                        data-testid="login-password"
                        aria-label="Password"
                        {...register('password')}
                    />
                    {errors.password && (
                        <FieldError errors={{ password: errors.password.message ?? '' }} name="password" />
                    )}
                    <FieldError errors={fieldErrors} name="password" />
                </div>
                <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: 'var(--fg-2)' }}>
                    <input type="checkbox" {...register('remember')} /> Keep me signed in
                </label>
                <button
                    type="submit"
                    className="btn primary"
                    data-testid="login-submit"
                    disabled={submitting}
                    aria-busy={submitting}
                    style={{ justifyContent: 'center', marginTop: 4 }}
                >
                    {submitting ? 'Signing in…' : 'Sign in'}
                </button>
            </form>
        </AuthLayout>
    );
}
