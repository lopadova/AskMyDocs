/*
 * Type definitions for the cross-mounted
 * `padosoft/laravel-pii-redactor-admin` SPA.
 *
 * Vendored from `vendor/padosoft/laravel-pii-redactor-admin/resources/js/`
 * because the host renders the package's React tree directly instead of
 * loading the package bundle in an iframe (v4.4/W2 — see ADR 0005).
 *
 * The shapes mirror the package's contract exactly so the API responses
 * — which are still served by the package's controllers — round-trip
 * unchanged.
 */

export type Page =
    | 'overview'
    | 'playground'
    | 'audit'
    | 'tokens'
    | 'detokenise'
    | 'detectors'
    | 'custom-rules'
    | 'settings';

export type StatusPayload = {
    package: Record<string, unknown>;
    strategies: string[];
    snapshot: Record<string, unknown> & {
        enabled?: boolean;
        default_strategy?: string;
        token_store?: { driver?: string };
        detectors?: unknown[];
    };
};

export type DataRow = Record<string, unknown>;

export type PiiRedactorAdminAbilities = {
    view: boolean;
    detokenise: boolean;
    rawSamples: boolean;
};

/*
 * The host derives the config payload from the auth-store +
 * package-config defaults. The shape matches the package's blade
 * controller `AdminShellController::__invoke()` so the rendered
 * components stay 1:1 with the iframe predecessor:
 *
 *   - `apiBase`        → '/admin/pii-redactor/api' (matches the
 *                        `pii-redactor-admin.api_prefix` env default)
 *   - `routePrefix`    → '/admin/pii-redactor' (informational only —
 *                        the cross-mounted SPA runs entirely inside
 *                        the host's TanStack Router; the package's
 *                        prefix is no longer driving navigation)
 *   - `userDisplay`    → resolved from the host auth store (name ||
 *                        email || 'Operator')
 *   - `abilities`      → derived from the host's Spatie roles to
 *                        mirror the BE Gates registered in
 *                        AppServiceProvider::registerPiiRedactorAdminGates()
 */
export type PiiRedactorAdminConfig = {
    apiBase: string;
    routePrefix: string;
    userDisplay: string;
    abilities: PiiRedactorAdminAbilities;
};
