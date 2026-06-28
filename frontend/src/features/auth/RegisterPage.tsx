import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import axios from 'axios';
import { AuthLayout, FieldError, type FieldErrors } from './AuthLayout';
import { register as registerAccount, me } from './auth.api';
import { useAuthStore } from '../../lib/auth-store';

const schema = z
    .object({
        name: z.string().min(1, 'Please enter your name'),
        email: z.string().email('Please enter a valid email address'),
        password: z.string().min(8, 'Password must be at least 8 characters'),
        password_confirmation: z.string().min(1, 'Please confirm your password'),
        invite_code: z.string().min(1, 'An invite code is required'),
    })
    .refine((values) => values.password === values.password_confirmation, {
        message: 'Passwords do not match',
        path: ['password_confirmation'],
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
    if (status === 422 && data?.errors) {
        return { fieldErrors: data.errors };
    }
    if (status === 429) {
        return { message: data?.message ?? 'Too many attempts — please try again in a moment.' };
    }
    return { message: data?.message ?? 'Registration failed. Check your details and try again.' };
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
        defaultValues: {
            name: '',
            email: '',
            password: '',
            password_confirmation: '',
            invite_code: '',
        },
    });

    const onSubmit = handleSubmit(async (values) => {
        setFieldErrors(undefined);
        setFormError(undefined);
        setSubmitting(true);
        try {
            await registerAccount(values);
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
            subtitle="Sign up with your invite code to join the workspace."
            footer={
                <span>
                    Already have an account?{' '}
                    <button
                        type="button"
                        onClick={onNavigateLogin}
                        data-testid="register-navigate-login"
                        style={{
                            background: 'transparent',
                            border: 0,
                            color: 'var(--fg-1)',
                            cursor: 'pointer',
                            padding: 0,
                            fontWeight: 500,
                        }}
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
                    <label htmlFor="name" style={labelStyle}>
                        Name
                    </label>
                    <input
                        id="name"
                        type="text"
                        autoComplete="name"
                        className="input"
                        data-testid="register-name"
                        aria-label="Name"
                        {...register('name')}
                    />
                    {errors.name && <FieldError errors={{ name: errors.name.message ?? '' }} name="name" />}
                    <FieldError errors={fieldErrors} name="name" />
                </div>
                <div>
                    <label htmlFor="email" style={labelStyle}>
                        Email
                    </label>
                    <input
                        id="email"
                        type="email"
                        autoComplete="email"
                        className="input"
                        data-testid="register-email"
                        aria-label="Email"
                        {...register('email')}
                    />
                    {errors.email && <FieldError errors={{ email: errors.email.message ?? '' }} name="email" />}
                    <FieldError errors={fieldErrors} name="email" />
                </div>
                <div>
                    <label htmlFor="password" style={labelStyle}>
                        Password
                    </label>
                    <input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        className="input"
                        data-testid="register-password"
                        aria-label="Password"
                        {...register('password')}
                    />
                    {errors.password && (
                        <FieldError errors={{ password: errors.password.message ?? '' }} name="password" />
                    )}
                    <FieldError errors={fieldErrors} name="password" />
                </div>
                <div>
                    <label htmlFor="password_confirmation" style={labelStyle}>
                        Confirm password
                    </label>
                    <input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        className="input"
                        data-testid="register-password-confirmation"
                        aria-label="Confirm password"
                        {...register('password_confirmation')}
                    />
                    {errors.password_confirmation && (
                        <FieldError
                            errors={{ password_confirmation: errors.password_confirmation.message ?? '' }}
                            name="password_confirmation"
                        />
                    )}
                </div>
                <div>
                    <label htmlFor="invite_code" style={labelStyle}>
                        Invite code
                    </label>
                    <input
                        id="invite_code"
                        type="text"
                        autoComplete="one-time-code"
                        className="input"
                        data-testid="register-invite-code"
                        aria-label="Invite code"
                        {...register('invite_code')}
                    />
                    {errors.invite_code && (
                        <FieldError errors={{ invite_code: errors.invite_code.message ?? '' }} name="invite_code" />
                    )}
                    <FieldError errors={fieldErrors} name="invite_code" />
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
