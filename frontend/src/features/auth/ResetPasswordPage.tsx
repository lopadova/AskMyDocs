import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import axios from 'axios';
import { AuthLayout, FieldError, type FieldErrors } from './AuthLayout';
import { reset } from './auth.api';

const schema = z
    .object({
        password: z.string().min(12, 'Use at least 12 characters'),
        password_confirmation: z.string().min(12, 'Use at least 12 characters'),
    })
    .refine((v) => v.password === v.password_confirmation, {
        path: ['password_confirmation'],
        message: 'Passwords do not match',
    });

type FormValues = z.infer<typeof schema>;

export type ResetPasswordPageProps = {
    token: string;
    email: string;
    onDone?: () => void;
};

export function ResetPasswordPage({ token, email, onDone }: ResetPasswordPageProps) {
    const [fieldErrors, setFieldErrors] = useState<FieldErrors | undefined>();
    const [formError, setFormError] = useState<string | undefined>();
    const [submitting, setSubmitting] = useState(false);
    const [done, setDone] = useState(false);

    const missingContext = useMemo(() => !token || !email, [token, email]);

    const {
        register,
        handleSubmit,
        formState: { errors },
    } = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { password: '', password_confirmation: '' },
    });

    const onSubmit = handleSubmit(async (values) => {
        if (missingContext) {
            setFormError('Invalid reset link. Request a new one from the forgot password page.');
            return;
        }
        setFieldErrors(undefined);
        setFormError(undefined);
        setSubmitting(true);
        try {
            await reset(token, email, values.password, values.password_confirmation);
            setDone(true);
            return;
        } catch (err) {
            if (axios.isAxiosError(err)) {
                const data = err.response?.data as { message?: string; errors?: FieldErrors } | undefined;
                if (err.response?.status === 422 && data?.errors) {
                    setFieldErrors(data.errors);
                    return;
                }
                setFormError(data?.message ?? 'Reset failed. Request a new link and try again.');
                return;
            }
            setFormError('Something went wrong. Please try again.');
        } finally {
            setSubmitting(false);
        }
    });

    if (done) {
        return (
            <AuthLayout
                title="Password updated"
                subtitle="You can now sign in with your new password."
                footer={
                    <button
                        type="button"
                        onClick={onDone}
                        style={{ background: 'transparent', border: 0, color: 'var(--fg-1)', cursor: 'pointer', fontWeight: 500 }}
                    >
                        Go to sign in
                    </button>
                }
            >
                <div
                    role="status"
                    style={{
                        padding: '14px 16px',
                        borderRadius: 10,
                        background: 'rgba(16,185,129,.12)',
                        border: '1px solid rgba(16,185,129,.3)',
                        color: 'var(--ok)',
                        fontSize: 13,
                    }}
                >
                    Your password has been changed successfully.
                </div>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout title="Set a new password" subtitle={email ? `Resetting for ${email}` : 'Missing email — request a new link.'}>
            <form onSubmit={onSubmit} noValidate style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                {formError && (
                    <div
                        role="alert"
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
                    <label
                        htmlFor="password"
                        style={{ fontSize: 12, color: 'var(--fg-2)', display: 'block', marginBottom: 6 }}
                    >
                        New password
                    </label>
                    <input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        className="input"
                        {...register('password')}
                    />
                    {errors.password && (
                        <FieldError errors={{ password: errors.password.message ?? '' }} name="password" />
                    )}
                    <FieldError errors={fieldErrors} name="password" />
                </div>
                <div>
                    <label
                        htmlFor="password_confirmation"
                        style={{ fontSize: 12, color: 'var(--fg-2)', display: 'block', marginBottom: 6 }}
                    >
                        Confirm new password
                    </label>
                    <input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        className="input"
                        {...register('password_confirmation')}
                    />
                    {errors.password_confirmation && (
                        <FieldError
                            errors={{ password_confirmation: errors.password_confirmation.message ?? '' }}
                            name="password_confirmation"
                        />
                    )}
                </div>
                <button
                    type="submit"
                    className="btn primary"
                    disabled={submitting || missingContext}
                    aria-busy={submitting}
                    style={{ justifyContent: 'center', marginTop: 4 }}
                >
                    {submitting ? 'Updating…' : 'Update password'}
                </button>
            </form>
        </AuthLayout>
    );
}
