/*
 * PiiRedactorView — iframe mount of the
 * `padosoft/laravel-pii-redactor-admin` v1.0.2 SPA console.
 *
 * Mount strategy: IFRAME.
 *
 * Why iframe and not cross-mount: the package ships React 19 + Tailwind
 * v4, while the AskMyDocs SPA is React 18 + a fully-handcrafted CSS
 * variable theme (no Tailwind). Cross-mounting would require either:
 *   (a) shipping two React runtimes in the same window (warns + risks
 *       hook-rule violations across module boundaries), or
 *   (b) downgrading the package bundle to React 18 (forks the package).
 * Iframe is one extra HTTP roundtrip + zero risk of bundle conflict +
 * the package's pre-built bundle keeps working unchanged.
 *
 * The iframe URL points at the package web prefix
 * (`PII_REDACTOR_ADMIN_ROUTE_PREFIX`, default `admin/pii-redactor`).
 * We hardcode the default here because:
 *   1. The Vite build doesn't have access to the runtime Laravel env,
 *   2. Operators who change the prefix would also update this constant
 *      in the same change-set.
 *
 * The host page is wrapped in the standard AppShell so the AskMyDocs
 * sidebar + topbar + breadcrumbs stay visible.
 */
import { useEffect, useRef, useState } from 'react';

const PII_REDACTOR_BASE_URL = '/admin/pii-redactor';

export function PiiRedactorView() {
    const [loadState, setLoadState] = useState<'loading' | 'ready' | 'error'>('loading');
    const iframeRef = useRef<HTMLIFrameElement | null>(null);

    useEffect(() => {
        // Belt-and-braces fallback: if the iframe never reports `load`
        // within 10 s (e.g. the env flag is off and Laravel returns 404),
        // surface an error state instead of a perpetual spinner. The
        // happy path resolves in <500 ms; 10 s is a generous ceiling.
        const id = window.setTimeout(() => {
            setLoadState((prev) => (prev === 'loading' ? 'error' : prev));
        }, 10_000);
        return () => window.clearTimeout(id);
    }, []);

    return (
        <div
            data-testid="admin-pii-redactor-host"
            data-state={loadState}
            style={{
                flex: 1,
                display: 'flex',
                flexDirection: 'column',
                background: 'var(--bg-0)',
                color: 'var(--fg-1)',
                position: 'relative',
            }}
        >
            {loadState === 'loading' && (
                <div
                    data-testid="admin-pii-redactor-loading"
                    style={{
                        position: 'absolute',
                        inset: 0,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        pointerEvents: 'none',
                        fontFamily: 'var(--font-sans)',
                        fontSize: 13,
                        color: 'var(--fg-2)',
                    }}
                >
                    <span className="shimmer" style={{ padding: '6px 18px', borderRadius: 8 }}>
                        Loading PII Redactor…
                    </span>
                </div>
            )}
            {loadState === 'error' && (
                <div
                    data-testid="admin-pii-redactor-error"
                    role="alert"
                    style={{
                        flex: 1,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        padding: 40,
                        fontFamily: 'var(--font-sans)',
                    }}
                >
                    <div
                        className="panel popin"
                        style={{
                            maxWidth: 480,
                            padding: '24px 24px 22px',
                            textAlign: 'center',
                        }}
                    >
                        <h2 style={{ fontSize: 17, fontWeight: 600, margin: '0 0 8px' }}>
                            PII Redactor admin is unavailable
                        </h2>
                        <p style={{ fontSize: 13, color: 'var(--fg-2)', margin: 0, lineHeight: 1.55 }}>
                            The console did not load. Confirm{' '}
                            <code>PII_REDACTOR_ADMIN_ENABLED=true</code> in the host
                            environment, then run <code>php artisan config:clear</code>.
                        </p>
                    </div>
                </div>
            )}
            <iframe
                ref={iframeRef}
                src={PII_REDACTOR_BASE_URL}
                title="PII Redactor Admin"
                data-testid="admin-pii-redactor-iframe"
                onLoad={() => setLoadState('ready')}
                onError={() => setLoadState('error')}
                style={{
                    flex: 1,
                    width: '100%',
                    border: 0,
                    background: 'var(--bg-0)',
                    visibility: loadState === 'error' ? 'hidden' : 'visible',
                }}
            />
        </div>
    );
}
