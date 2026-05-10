/*
 * EvalHarnessView — iframe mount of the
 * `padosoft/eval-harness-ui` v1.0.0 SPA console.
 *
 * Mount strategy: IFRAME.
 *
 * Why iframe and not cross-mount: the package ships its own React + Vite
 * bundle that hydrates against a Blade-rendered bootstrap payload
 * (api_base / tenant_header / locale / metric_labels). Cross-mounting
 * would require replicating the bootstrap pipeline inside the AskMyDocs
 * SPA AND keeping two React runtimes in the same window. Iframe
 * isolates the two cleanly: the AskMyDocs shell (sidebar / topbar /
 * breadcrumbs) stays in our React tree; the eval dashboard lives at
 * its own URL inside the frame.
 *
 * Mirrors the exact pattern used for {@see PiiRedactorView} (sub-PR 5)
 * and {@see FlowsView} (sub-PR 6).
 *
 * The iframe URL points at the package web prefix
 * (`EVAL_HARNESS_UI_PREFIX`, default `admin/eval-harness`). Hardcoded
 * here for the same reasons documented on the sibling views: the Vite
 * build does not see the runtime Laravel env, and operators who change
 * `EVAL_HARNESS_UI_PREFIX` will also update this constant in the same
 * change-set.
 *
 * Status probing: we GET a JSON-only API endpoint
 * (`/admin/eval-harness/api/bootstrap`) with `Accept: application/json`
 * and `redirect: 'manual'`. The package controller returns:
 *   - 200 + JSON when both fences are open (env=true AND non-prod)
 *     AND the user has the `eval-harness.viewer` Gate
 *   - 401/403 when the auth/Gate fences reject
 *   - 404 when env=false OR APP_ENV=production
 *
 * Why the JSON endpoint + manual redirect: an HTML probe of the SPA
 * mount URL would falsely mark "ready" if the user's session has
 * expired — Laravel's `auth` middleware 302→/login, fetch follows
 * the redirect by default, and the login page returns 200 HTML which
 * passes `response.ok`. JSON Accept + manual-redirect means an
 * expired session surfaces as an opaqueredirect (status 0) → treated
 * as `error`, never `ready`. Same defence as FlowsView (sub-PR 6).
 */
import { useEffect, useState } from 'react';

const EVAL_HARNESS_BASE_URL = '/admin/eval-harness';
const EVAL_HARNESS_PROBE_URL = '/admin/eval-harness/api/bootstrap';

export function EvalHarnessView() {
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

        void fetch(EVAL_HARNESS_PROBE_URL, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            redirect: 'manual',
            signal: controller.signal,
        })
            .then((response) => {
                if (!active) {
                    return;
                }
                setLoadState(response.ok ? 'ready' : 'error');
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
            data-testid="admin-eval-harness-host"
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
                    data-testid="admin-eval-harness-loading"
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
                        Loading Eval Harness…
                    </span>
                </div>
            )}
            {loadState === 'error' && (
                <div
                    data-testid="admin-eval-harness-error"
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
                            Eval Harness is unavailable
                        </h2>
                        <p style={{ fontSize: 13, color: 'var(--fg-2)', margin: 0, lineHeight: 1.55 }}>
                            The dashboard did not load. Confirm{' '}
                            <code>EVAL_HARNESS_UI_ENABLED=true</code> AND{' '}
                            <code>APP_ENV</code> is not <code>production</code> in
                            the host environment, then run{' '}
                            <code>php artisan config:clear</code>.
                        </p>
                    </div>
                </div>
            )}
            <iframe
                src={EVAL_HARNESS_BASE_URL}
                title="Eval Harness"
                data-testid="admin-eval-harness-iframe"
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
