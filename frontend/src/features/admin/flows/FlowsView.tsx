/*
 * FlowsView — iframe mount of the `padosoft/laravel-flow-admin` v1.0.0
 * Blade + Alpine cockpit.
 *
 * Mount strategy: IFRAME.
 *
 * Why iframe and not cross-mount: the package is a server-rendered
 * Blade application with Alpine.js sprinkles (Vite-built CSS + a
 * minimal JS bundle). It is NOT a React SPA — there is no React tree
 * to mount into the AskMyDocs root. Even if we wrapped its bundle in
 * a React component, two completely different rendering models would
 * fight over the same DOM subtree on every navigation. The iframe
 * isolates the two cleanly: the AskMyDocs shell (sidebar / topbar /
 * breadcrumbs) stays in our React tree; the cockpit lives at its own
 * URL inside the frame.
 *
 * Mirrors the exact pattern used for {@see PiiRedactorView} (sub-PR 5).
 *
 * The iframe URL points at the package web prefix
 * (`FLOW_ADMIN_PREFIX`, default `admin/flows`). Hardcoded here for
 * the same reasons documented on PiiRedactorView: the Vite build
 * does not see the runtime Laravel env, and operators who change
 * `FLOW_ADMIN_PREFIX` will also update this constant in the same
 * change-set.
 *
 * Status probing: we GET `/admin/flows` (the cockpit's overview
 * route) with `Accept: text/html` and check for a 200 / 3xx. A 404
 * means `FLOW_ADMIN_ENABLED=false` and we surface the explicit error
 * state per R14 — never a blank iframe under a 200.
 */
import { useEffect, useState } from 'react';

const FLOW_ADMIN_BASE_URL = '/admin/flows';

export function FlowsView() {
    const [loadState, setLoadState] = useState<'loading' | 'ready' | 'error'>('loading');

    useEffect(() => {
        let active = true;
        const controller = new AbortController();

        // Belt-and-braces fallback: if the probe doesn't respond
        // within 10 s, surface an explicit error state.
        const id = window.setTimeout(() => {
            controller.abort();
            if (active) {
                setLoadState((prev) => (prev === 'loading' ? 'error' : prev));
            }
        }, 10_000);

        void fetch(FLOW_ADMIN_BASE_URL, {
            method: 'GET',
            credentials: 'same-origin',
            // Don't follow the package's internal redirects — we only
            // need to know whether the route resolves.
            redirect: 'manual',
            headers: { Accept: 'text/html' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!active) {
                    return;
                }
                // 200 (rendered HTML), 0 (opaque-redirect after
                // redirect: 'manual') or 3xx all signal the route
                // is registered. 404 means the master switch is off.
                const ok =
                    response.status === 0 ||
                    (response.status >= 200 && response.status < 400);
                setLoadState(ok ? 'ready' : 'error');
            })
            .catch(() => {
                if (!active) {
                    return;
                }
                setLoadState('error');
            })
            .finally(() => {
                window.clearTimeout(id);
            });

        return () => {
            active = false;
            controller.abort();
            window.clearTimeout(id);
        };
    }, []);

    return (
        <div
            data-testid="admin-flows-host"
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
                    data-testid="admin-flows-loading"
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
                        Loading Flow cockpit…
                    </span>
                </div>
            )}
            {loadState === 'error' && (
                <div
                    data-testid="admin-flows-error"
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
                            Flow cockpit is unavailable
                        </h2>
                        <p style={{ fontSize: 13, color: 'var(--fg-2)', margin: 0, lineHeight: 1.55 }}>
                            The console did not load. Confirm{' '}
                            <code>FLOW_ADMIN_ENABLED=true</code> in the host environment,
                            then run <code>php artisan config:clear</code>.
                        </p>
                    </div>
                </div>
            )}
            <iframe
                src={FLOW_ADMIN_BASE_URL}
                title="Flow Admin"
                data-testid="admin-flows-iframe"
                style={{
                    flex: 1,
                    width: '100%',
                    border: 0,
                    background: 'var(--bg-0)',
                    visibility: loadState === 'ready' ? 'visible' : 'hidden',
                }}
            />
        </div>
    );
}
