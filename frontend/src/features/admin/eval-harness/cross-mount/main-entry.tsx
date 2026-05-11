/*
 * Cross-mount entry point for the
 * `padosoft/eval-harness-ui` v0.1.0 SPA.
 *
 * v4.4/W3 — adapted from
 * `vendor/padosoft/eval-harness-ui/resources/js/main.tsx`.
 *
 * THREE material differences vs the upstream `main.tsx`:
 *
 *   1. The bottom-of-file `createRoot(...).render(<App />)` is REMOVED.
 *      The host's TanStack Router mounts `<EvalHarnessUiApp />` via
 *      `<EvalHarnessView />` instead — sharing the host's React +
 *      ReactDOM + Sanctum cookie + axios instance instead of running
 *      a second React tree inside an iframe.
 *
 *   2. The blade-injected DOM lookup (`document.getElementById('eval-
 *      harness-ui-bootstrap')` + `document.getElementById('eval-
 *      harness-ui-root')`) is REMOVED. The host derives the same
 *      `appConfig` shape (`AppBootstrapConfig`) host-side from the
 *      `useAuthStore` + the package config defaults documented in
 *      `vendor/padosoft/eval-harness-ui/src/Http/Controllers/EvalHarnessUiController.php::configPayload()`.
 *      The `apiBase` / `routeBase` defaults match the operator
 *      defaults — operators who change `EVAL_HARNESS_UI_PREFIX` /
 *      `EVAL_HARNESS_API_BASE` env vars also redeploy the host
 *      bundle, the same operational coupling the iframe predecessor
 *      had.
 *
 *   3. The package's internal `<BrowserRouter basename={...}>` is
 *      preserved. Two routers coexist at different scopes: the host
 *      TanStack Router owns `/app/admin/eval-harness` shell mount;
 *      this `BrowserRouter` owns `/admin/eval-harness/{view?}` sub-
 *      navigation. Acceptable scope-boundary — the package's 8 sub-
 *      pages stay router-internal, the host shell stays host-router-
 *      internal. Cost: ~14 KB of `react-router-dom` v6.30.1 in the
 *      bundle, paid once across the cross-mount surface.
 *
 * R7 / R14: failures from the package's internal `evalHarnessApi`
 * client surface as `<ErrorPanel />` (already vendored) — never
 * silently swallowed.
 *
 * R11 / R30: testid hooks live on the cross-mount `data-mount` shell
 * + on every NavLink in the package's `<AppShell />`; tenant header
 * is forwarded automatically by the host axios instance because the
 * package's `evalHarnessApi.requestOptions()` adds it to every call.
 */
import { StrictMode } from 'react';
import { BrowserRouter } from 'react-router-dom';
import App from './app';
import { AppContextProvider } from './context/AppContext';
import type { AppBootstrapConfig } from './utils/bootstrap';
import './eval-harness-ui.css';

export type EvalHarnessUiAppProps = {
    config: AppBootstrapConfig;
    apiBase: string;
    routeBase: string;
};

export default function EvalHarnessUiApp({ config, apiBase, routeBase }: EvalHarnessUiAppProps) {
    const baseName = normaliseBaseName(routeBase);
    const version = config.ui_version || '0.0.0';
    return (
        <StrictMode>
            <BrowserRouter basename={baseName}>
                <AppContextProvider apiBase={apiBase} config={config}>
                    <App title="Eval Harness UI" version={version} />
                </AppContextProvider>
            </BrowserRouter>
        </StrictMode>
    );
}

/**
 * Mirrors the basename-normalisation logic the upstream `main.tsx`
 * applied to `rootNode.dataset.routeBase` so the resulting path
 * always starts with `/` (or is `undefined` when no route base was
 * supplied).
 */
function normaliseBaseName(routeBase: string): string | undefined {
    const trimmed = routeBase.trim();
    if (trimmed === '') {
        return undefined;
    }
    return `/${trimmed.replace(/\/+/g, '/').replace(/^\/+|\/+$/g, '')}`;
}
