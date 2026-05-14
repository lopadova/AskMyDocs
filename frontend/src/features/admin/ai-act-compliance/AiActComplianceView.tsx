import { useEffect, useRef, useState } from 'react';

import { AdminShell } from '../shell/AdminShell';

/**
 * v6.0/W7 — AI Act compliance admin landing.
 *
 * Cross-mounts the standalone `padosoft/laravel-ai-act-compliance-admin`
 * SPA (v1.1+, ported from the Claude Design handoff bundle) into the
 * AskMyDocs admin shell via an iframe with a `name="ai-act-cross-mount"`
 * isolation boundary. Same pattern as the pii-redactor / eval-harness
 * cross-mounts in v4.4. Once Padosoft ships the SDK shared
 * iframe-resizer, this can swap to a transparent embed.
 *
 * The iframe target URL is the path where the package PHP service
 * provider has published the Vite bundle (config:
 * `compliance.admin.mount_prefix` — defaults to
 * `/admin/ai-act-compliance`). The PHP backend serves the bundle via
 * the package's `serve()` controller that returns the published
 * `app.blade.php` (provided by laravel-ai-act-compliance-admin's
 * service provider).
 */
export function AiActComplianceView() {
    const iframeRef = useRef<HTMLIFrameElement | null>(null);
    const [loaded, setLoaded] = useState(false);
    const [errored, setErrored] = useState(false);
    const targetUrl = '/admin/ai-act-compliance/embed';

    useEffect(() => {
        if (errored) return;
        const timer = window.setTimeout(() => {
            if (!loaded) setErrored(true);
        }, 12_000);
        return () => window.clearTimeout(timer);
    }, [loaded, errored]);

    return (
        <AdminShell section="ai-act-compliance">
            <section
                data-testid="admin-ai-act-compliance"
                data-state={errored ? 'error' : loaded ? 'ready' : 'loading'}
                aria-busy={!loaded && !errored}
                aria-labelledby="admin-ai-act-compliance-title"
                style={{
                    flex: 1,
                    display: 'flex',
                    flexDirection: 'column',
                    minHeight: 0,
                    color: 'var(--fg-1)',
                    fontFamily: 'var(--font-sans)',
                }}
            >
                <header
                    style={{
                        padding: '14px 22px',
                        borderBottom: '1px solid var(--border-1)',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 12,
                    }}
                >
                    <h1
                        id="admin-ai-act-compliance-title"
                        data-testid="admin-ai-act-compliance-title"
                        style={{
                            margin: 0,
                            fontSize: 18,
                            fontWeight: 700,
                            letterSpacing: '-0.01em',
                        }}
                    >
                        AI Act compliance
                    </h1>
                    <span
                        data-testid="admin-ai-act-compliance-source"
                        style={{
                            padding: '2px 8px',
                            borderRadius: 999,
                            background: 'rgba(59,130,246,0.18)',
                            color: '#93c5fd',
                            fontSize: 11.5,
                            fontWeight: 600,
                            letterSpacing: '0.04em',
                            textTransform: 'uppercase',
                        }}
                    >
                        padosoft/laravel-ai-act-compliance-admin v1.1
                    </span>
                    <span style={{ flex: 1 }} />
                    <a
                        href={targetUrl}
                        target="_blank"
                        rel="noreferrer"
                        data-testid="admin-ai-act-compliance-open-tab"
                        style={{
                            fontSize: 12.5,
                            color: 'var(--fg-2)',
                            textDecoration: 'none',
                            padding: '6px 12px',
                            border: '1px solid var(--border-2)',
                            borderRadius: 8,
                        }}
                    >
                        Open in new tab ↗
                    </a>
                </header>

                {!loaded && !errored && (
                    <div
                        data-testid="admin-ai-act-compliance-loading"
                        style={{
                            flex: 1,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: 'var(--fg-2)',
                            fontSize: 13,
                        }}
                    >
                        Loading AI Act compliance panel…
                    </div>
                )}

                {errored && (
                    <div
                        data-testid="admin-ai-act-compliance-error"
                        role="alert"
                        style={{
                            flex: 1,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: 'var(--danger-fg)',
                            fontSize: 13,
                            padding: 24,
                            textAlign: 'center',
                        }}
                    >
                        Failed to load the AI Act compliance panel. Verify that
                        <code style={{ margin: '0 6px', fontFamily: 'var(--font-mono)' }}>
                            padosoft/laravel-ai-act-compliance-admin
                        </code>
                        is installed and the publishable assets are served at{' '}
                        <code>{targetUrl}</code>.
                    </div>
                )}

                <iframe
                    ref={iframeRef}
                    name="ai-act-cross-mount"
                    title="AI Act compliance admin panel"
                    src={targetUrl}
                    data-testid="admin-ai-act-compliance-iframe"
                    onLoad={() => setLoaded(true)}
                    onError={() => setErrored(true)}
                    style={{
                        flex: 1,
                        width: '100%',
                        border: 0,
                        background: 'transparent',
                        minHeight: 0,
                        display: loaded ? 'block' : 'none',
                    }}
                />
            </section>
        </AdminShell>
    );
}
