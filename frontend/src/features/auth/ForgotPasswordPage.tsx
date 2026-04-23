import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AuthLayout, FieldError } from './AuthLayout';
import { forgot } from './auth.api';

const schema = z.object({
    email: z.string().email('Please enter a valid email address'),
});

type FormValues = z.infer<typeof schema>;

export type ForgotPasswordPageProps = {
    onBackToLogin?: () => void;
};

export function ForgotPasswordPage({ onBackToLogin }: ForgotPasswordPageProps = {}) {
    const [submitting, setSubmitting] = useState(false);
    const [submitted, setSubmitted] = useState(false);

    const {
        register,
        handleSubmit,
        formState: { errors },
    } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { email: '' } });

    const onSubmit = handleSubmit(async (values) => {
        setSubmitting(true);
        try {
            await forgot(values.email);
        } catch {
            // Anti-enumeration: show success regardless. The backend
            // (PR2) returns 204 for both valid and unknown emails.
        } finally {
            setSubmitted(true);
            setSubmitting(false);
        }
    });

    if (submitted) {
        return (
            <AuthLayout
                title="Check your inbox"
                subtitle="If that email matches an account, a reset link is on its way. The link expires in 60 minutes."
                footer={
                    <button
                        type="button"
                        onClick={onBackToLogin}
                        style={{ background: 'transparent', border: 0, color: 'var(--fg-1)', cursor: 'pointer', fontWeight: 500 }}
                    >
                        Back to sign in
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
                    Request received. Watch for an email from AskMyDocs within a few minutes.
                </div>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout
            title="Reset your password"
            subtitle="Enter your email and we'll send a link to set a new one."
            footer={
                <button
                    type="button"
                    onClick={onBackToLogin}
                    style={{ background: 'transparent', border: 0, color: 'var(--fg-1)', cursor: 'pointer', fontWeight: 500 }}
                >
                    Back to sign in
                </button>
            }
        >
            <form onSubmit={onSubmit} noValidate style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                <div>
                    <label htmlFor="email" style={{ fontSize: 12, color: 'var(--fg-2)', display: 'block', marginBottom: 6 }}>
                        Email
                    </label>
                    <input id="email" type="email" autoComplete="email" className="input" {...register('email')} />
                    {errors.email && (
                        <FieldError errors={{ email: errors.email.message ?? '' }} name="email" />
                    )}
                </div>
                <button
                    type="submit"
                    className="btn primary"
                    disabled={submitting}
                    aria-busy={submitting}
                    style={{ justifyContent: 'center', marginTop: 4 }}
                >
                    {submitting ? 'Sending…' : 'Send reset link'}
                </button>
            </form>
        </AuthLayout>
    );
}
