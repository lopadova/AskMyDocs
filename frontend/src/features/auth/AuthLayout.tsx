import type { ReactNode } from 'react';
import { Icon } from '../../components/Icons';

export type AuthLayoutProps = {
    title: string;
    subtitle?: string;
    children: ReactNode;
    footer?: ReactNode;
};

/*
 * Dark-first centred card matching the design reference's auth pages.
 * Uses tokens (var(--bg-0), var(--panel), etc.) — no Tailwind classes
 * for layout.
 */
export function AuthLayout({ title, subtitle, children, footer }: AuthLayoutProps) {
    return (
        <div
            className="grid-bg"
            style={{
                minHeight: '100vh',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: '24px 16px',
                fontFamily: 'var(--font-sans)',
                color: 'var(--fg-1)',
            }}
        >
            <div
                className="panel popin"
                style={{
                    width: '100%',
                    maxWidth: 420,
                    padding: '32px 28px 28px',
                    background: 'var(--panel-solid)',
                    boxShadow: 'var(--shadow-lg)',
                }}
            >
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 22 }}>
                    <Icon.Logo size={28} />
                    <div>
                        <div style={{ fontSize: 15, fontWeight: 600, letterSpacing: '-0.01em' }}>AskMyDocs</div>
                        <div
                            style={{
                                fontSize: 10.5,
                                color: 'var(--fg-3)',
                                fontFamily: 'var(--font-mono)',
                                textTransform: 'uppercase',
                                letterSpacing: '0.04em',
                            }}
                        >
                            Enterprise
                        </div>
                    </div>
                </div>
                <h1 style={{ fontSize: 22, fontWeight: 600, margin: '0 0 6px', letterSpacing: '-0.02em' }}>{title}</h1>
                {subtitle && (
                    <p style={{ fontSize: 13, color: 'var(--fg-2)', margin: '0 0 22px' }}>{subtitle}</p>
                )}
                {children}
                {footer && (
                    <div
                        style={{
                            marginTop: 20,
                            paddingTop: 16,
                            borderTop: '1px solid var(--hairline)',
                            fontSize: 12,
                            color: 'var(--fg-3)',
                            textAlign: 'center',
                        }}
                    >
                        {footer}
                    </div>
                )}
            </div>
        </div>
    );
}

export type FieldErrors = Record<string, string[] | string>;

export function FieldError({ errors, name }: { errors: FieldErrors | undefined; name: string }) {
    if (!errors || !errors[name]) {
        return null;
    }
    const raw = errors[name];
    const msg = Array.isArray(raw) ? raw[0] : raw;
    return (
        <div style={{ fontSize: 12, color: 'var(--err)', marginTop: 6 }} role="alert">
            {msg}
        </div>
    );
}
