/*
 * EvalHarnessView — cross-mount of the
 * `padosoft/eval-harness-ui` v0.1.0 SPA dashboard.
 *
 * v4.4/W3 — replaces the v4.2/W4 iframe mount (see ADR 0005). The
 * previous iframe rationale (Tailwind v3 + handcrafted CSS host vs
 * Tailwind v3 + own bundle in the package) is RESOLVED by the v4.4/W1
 * Tailwind v4 host migration (commit `860d0aa`) and by re-emitting
 * the package's component-layer classes as plain CSS scoped under
 * `.ehu-shell` (see `cross-mount/eval-harness-ui.css`).
 *
 * Mount strategy: cross-mount. The package's React tree renders
 * directly inside the host's TanStack Router, sharing one React
 * runtime, one Sanctum cookie, one axios instance. No iframe means
 * no double React, no double layout reflow, and one fewer HTTP
 * round-trip on first paint. Same pattern as the W2 cross-mount of
 * `padosoft/laravel-pii-redactor-admin` (see {@see PiiRedactorView}).
 *
 * Routing: the package internally uses `react-router-dom` v6's
 * `<BrowserRouter basename="/app/admin/eval-harness">` for its 8 sub-
 * pages (Dashboard / Reports / ReportDetail / Compare / Trend /
 * Adversarial / AdversarialDetail / LiveBatches). The host's
 * TanStack Router owns the `/app/admin/eval-harness` shell mount;
 * the cross-mount uses `BrowserRouter` for everything below it. Two
 * routers coexist at different scopes — acceptable cost: ~14 KB of
 * `react-router-dom` for the package's existing wiring vs the
 * full-rewrite cost of porting 8 pages onto TanStack child routes.
 *
 * Config resolution (Copilot iter 2 finding #2): the cross-mount
 * fetches the runtime config from
 * `GET /api/admin/eval-harness/bootstrap-config` (the host endpoint
 * mounted in `routes/api.php`) at mount time. The endpoint replays
 * `config/eval-harness-ui.php` so operators' tuned `metric_labels`,
 * `polling`, `locale`, and `shortcuts` settings actually reach the
 * SPA — iter 1 hard-coded an empty payload host-side, which
 * diverged from what the iframe predecessor delivered through the
 * package's blade `<script id="eval-harness-ui-bootstrap">` tag.
 *
 *   - `apiBase` → `/admin/eval-harness/api` (matches
 *     `EVAL_HARNESS_API_BASE` env default — the path the package's
 *     blade controller mounts the eval-harness JSON API at).
 *   - `routeBase` → `/app/admin/eval-harness` (the path the host
 *     TanStack shell mounts the cross-mount at; this is NOT the
 *     `EVAL_HARNESS_UI_PREFIX` env value, which targets the
 *     package's blade route at `/admin/eval-harness/{view?}`).
 *
 * The three fail-closed fences (env flag `EVAL_HARNESS_UI_ENABLED`,
 * `APP_ENV=production`, Gate `eval-harness.viewer`) stay enforced
 * server-side on every package API route — the cross-mount only
 * changes the FRONTEND mount strategy. A viewer who somehow reaches
 * the route still hits a 4xx from Laravel. The host bootstrap-config
 * endpoint is gated by `auth:sanctum` + `can:eval-harness.viewer`
 * with the same role allowlist (see
 * `app/Http/Controllers/Api/Admin/EvalHarnessUiBootstrapController.php`).
 */
import { useEffect, useState } from 'react';
import { api } from '../../../lib/api';
import { selectCurrentHash, useTeamStore } from '../../../lib/team-store';
import EvalHarnessUiApp from './cross-mount/main-entry';
import type { AppBootstrapConfig } from './cross-mount/utils/bootstrap';

/*
 * `apiBase` targets the package's eval-harness BE API routes mounted
 * at `/admin/eval-harness/api/*` (the `EVAL_HARNESS_API_BASE` env
 * default). The cross-mount forwards every API call through this
 * prefix; operators who change `EVAL_HARNESS_API_BASE` also redeploy
 * the host bundle, same operational coupling the iframe predecessor
 * had.
 */
const EVAL_HARNESS_API_BASE = '/admin/eval-harness/api';

/*
 * `EVAL_HARNESS_ROUTE_BASE` is the FE mount path INSIDE the host
 * TanStack shell — i.e. the URL prefix the package's internal
 * `<BrowserRouter basename={...}>` aligns to. This is `/app/admin/
 * eval-harness` and intentionally NOT the `EVAL_HARNESS_UI_PREFIX`
 * env value:
 *
 *   - `EVAL_HARNESS_UI_PREFIX` (default `admin/eval-harness`) is the
 *     LARAVEL prefix where the package mounts its blade route +
 *     `/api/*` controllers — server-side, not browser-visible to the
 *     SPA shell.
 *   - `/app/admin/eval-harness` is where the host TanStack route
 *     `appRoute > 'admin/eval-harness'` puts the cross-mount in the
 *     browser URL bar.
 *
 * Two distinct concepts that happened to share the suffix; the
 * cross-mount has to use the host TanStack path so back/forward
 * navigation from the BrowserRouter sub-routes resolves correctly.
 */
// Built per-render from the active team hash — the cross-mounted
// BrowserRouter basename must mirror the host URL /app/{hash}/admin/…
// or its internal sub-route links would drop the team segment.
const evalHarnessRouteBase = (teamHash: string): string =>
    // Empty hash (store not synced yet) would yield `/app//admin/eval-harness` and
    // break the cross-mounted BrowserRouter basename. Mirror NotificationBell's
    // fallback to the legacy non-team URL.
    teamHash ? `/app/${teamHash}/admin/eval-harness` : '/app/admin/eval-harness';

const EVAL_HARNESS_BOOTSTRAP_CONFIG_URL = '/api/admin/eval-harness/bootstrap-config';

/*
 * A real eval-harness DATA endpoint, probed once at mount to confirm the
 * package's JSON API is actually wired + reachable in THIS deployment.
 *
 * `bootstrap-config` is a HOST endpoint that always answers 200, so it does
 * NOT tell us whether the eval data backend works. When `EVAL_HARNESS_UI_ENABLED`
 * is off the `/admin/eval-harness/api/*` routes 404; when it is on but the
 * package's blade SPA shadows the JSON path (no host-wired data routes), they
 * 500. In both cases the cross-mount would mount and render nothing but error
 * panels. Probing a data endpoint lets the host show a single clean
 * "unavailable" landing instead — so the feature is safe whether its flag is
 * ON or OFF (never a raw 500 / error-panel storm reaching the operator).
 */
const EVAL_HARNESS_DATA_PROBE_URL = `${EVAL_HARNESS_API_BASE}/reports`;

/*
 * Hard-coded fallback payload — used ONLY when the bootstrap-config
 * fetch fails (e.g. env=false at the package level rejects the
 * response with a 404, or the host endpoint itself errors). The SPA
 * still mounts in degraded mode with empty metric labels + default
 * polling + en locale; the dashboard's fan-out fetches will then
 * surface the underlying BE error through the package's existing
 * `<ErrorPanel />` so the operator sees what's broken (R7 / R14).
 */
const FALLBACK_CONFIG: AppBootstrapConfig = {
    ui_version: '0.1.0',
    metric_labels: {},
    tenant_header: null,
    polling: {},
    locale: 'en',
    shortcuts: { commandPalette: 'mod+k' },
};

type LoadState = 'loading' | 'ready' | 'unavailable';

export function EvalHarnessView() {
    const [config, setConfig] = useState<AppBootstrapConfig | null>(null);
    const [state, setState] = useState<LoadState>('loading');
    const teamHash = useTeamStore(selectCurrentHash) ?? '';

    useEffect(() => {
        let active = true;
        setState('loading');

        // Fetch the SPA config (host endpoint, tolerant of failure) AND probe a
        // real data endpoint to decide whether the eval data API is usable here.
        Promise.allSettled([
            api.get<AppBootstrapConfig>(EVAL_HARNESS_BOOTSTRAP_CONFIG_URL),
            api.get(EVAL_HARNESS_DATA_PROBE_URL),
        ]).then(([cfgResult, dataResult]) => {
            if (!active) {
                return;
            }
            setConfig(cfgResult.status === 'fulfilled' ? cfgResult.value.data : FALLBACK_CONFIG);
            // Only mount the cross-mount when the data API actually answered.
            // Otherwise show a single clean unavailable landing rather than a
            // storm of error panels / a raw 500 (R14: degrade loudly but clean).
            setState(dataResult.status === 'fulfilled' ? 'ready' : 'unavailable');
        });

        return () => {
            active = false;
        };
    }, []);

    return (
        <div
            data-testid="admin-eval-harness-host"
            data-state={state}
            data-mount="cross-mount"
            style={{
                flex: 1,
                display: 'flex',
                flexDirection: 'column',
                background: 'var(--bg-0)',
                color: 'var(--fg-1)',
                position: 'relative',
                minHeight: 0,
            }}
        >
            {state === 'loading' && (
                <div
                    data-testid="admin-eval-harness-loading"
                    style={{
                        flex: 1,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
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

            {state === 'unavailable' && (
                <div
                    data-testid="admin-eval-harness-unavailable"
                    role="status"
                    style={{
                        flex: 1,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        padding: 40,
                        fontFamily: 'var(--font-sans)',
                    }}
                >
                    <div className="panel" style={{ maxWidth: 520, padding: '24px 24px 22px', textAlign: 'center' }}>
                        <h2 style={{ fontSize: 17, fontWeight: 600, margin: '0 0 8px' }}>
                            Eval Harness data API is not available
                        </h2>
                        <p style={{ fontSize: 13, color: 'var(--fg-2)', margin: 0, lineHeight: 1.55 }}>
                            The <code style={{ fontFamily: 'var(--font-mono)' }}>padosoft/eval-harness-ui</code> data
                            endpoints aren&rsquo;t reachable in this environment. Set{' '}
                            <code>EVAL_HARNESS_UI_ENABLED=true</code> and wire the package&rsquo;s JSON API
                            (then <code>php artisan config:clear</code>) to enable the dashboards.
                        </p>
                    </div>
                </div>
            )}

            {state === 'ready' && config !== null && (
                <EvalHarnessUiApp
                    config={config}
                    apiBase={EVAL_HARNESS_API_BASE}
                    routeBase={evalHarnessRouteBase(teamHash)}
                />
            )}
        </div>
    );
}
