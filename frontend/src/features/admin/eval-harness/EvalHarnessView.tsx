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
 * `<BrowserRouter basename="/admin/eval-harness">` for its 8 sub-
 * pages (Dashboard / Reports / ReportDetail / Compare / Trend /
 * Adversarial / AdversarialDetail / LiveBatches). The host's
 * TanStack Router owns the `/app/admin/eval-harness` shell mount;
 * the cross-mount uses `BrowserRouter` for everything below it. Two
 * routers coexist at different scopes — acceptable cost: ~14 KB of
 * `react-router-dom` for the package's existing wiring vs the
 * full-rewrite cost of porting 8 pages onto TanStack child routes.
 *
 * Config resolution: the package's blade controller injects an
 * `appConfigJson` payload (`ui_version`, `metric_labels`,
 * `tenant_header`, `polling`, `locale`, `shortcuts`) — see
 * `vendor/padosoft/eval-harness-ui/src/Http/Controllers/EvalHarnessUiController.php::configPayload()`.
 * The cross-mount derives the same shape host-side using:
 *
 *   - `ui_version` → known constant matching the package's
 *     `EvalHarnessUiController::configPayload()` literal `'0.1.0'`
 *     (operators upgrading the package update this constant in the
 *     same change-set, the same operational coupling the iframe
 *     predecessor had via the blade view-resolution pipeline).
 *   - `tenant_header` → constant `'X-Eval-Harness-Tenant'` matching
 *     the `EvalHarnessUiTenantHeader` middleware injection contract.
 *   - `metric_labels` / `polling` / `locale` / `shortcuts` → the
 *     package defaults from `parseBootstrapConfig` — the host doesn't
 *     override them today (the iframe predecessor used the same
 *     defaults via the blade payload).
 *   - `apiBase` → `/admin/eval-harness/api` (matches
 *     `EVAL_HARNESS_API_BASE` env default).
 *   - `routeBase` → `/admin/eval-harness` (matches
 *     `EVAL_HARNESS_UI_PREFIX` env default).
 *
 * The three fail-closed fences (env flag `EVAL_HARNESS_UI_ENABLED`,
 * `APP_ENV=production`, Gate `eval-harness.viewer`) stay enforced
 * server-side on every package API route — the cross-mount only
 * changes the FRONTEND mount strategy. A viewer who somehow reaches
 * the route still hits a 4xx from Laravel.
 */
import { useMemo } from 'react';
import EvalHarnessUiApp from './cross-mount/main-entry';
import { parseBootstrapConfig, type AppBootstrapConfig } from './cross-mount/utils/bootstrap';

/*
 * `apiBase` targets the package's BE API routes which Laravel mounts
 * at `/admin/eval-harness/api/*` (the `EVAL_HARNESS_API_BASE` env
 * default). `routeBase` is the FE mount path inside the host TanStack
 * shell — `/app/admin/eval-harness` — so the package's internal
 * `BrowserRouter basename` aligns with the URL the browser sees.
 * Operators who change `EVAL_HARNESS_UI_PREFIX` also redeploy the
 * host bundle, the same operational coupling the iframe predecessor
 * had.
 */
const EVAL_HARNESS_API_BASE = '/admin/eval-harness/api';
const EVAL_HARNESS_ROUTE_BASE = '/app/admin/eval-harness';
const EVAL_HARNESS_UI_VERSION = '0.1.0';
const EVAL_HARNESS_TENANT_HEADER = 'X-Eval-Harness-Tenant';

export function EvalHarnessView() {
    const config = useMemo<AppBootstrapConfig>(() => {
        return parseBootstrapConfig(
            JSON.stringify({
                ui_version: EVAL_HARNESS_UI_VERSION,
                metric_labels: {},
                tenant_header: EVAL_HARNESS_TENANT_HEADER,
                polling: {},
                locale: 'en',
                shortcuts: { commandPalette: 'mod+k' },
            }),
        );
    }, []);

    return (
        <div
            data-testid="admin-eval-harness-host"
            data-state="ready"
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
            <EvalHarnessUiApp
                config={config}
                apiBase={EVAL_HARNESS_API_BASE}
                routeBase={EVAL_HARNESS_ROUTE_BASE}
            />
        </div>
    );
}
