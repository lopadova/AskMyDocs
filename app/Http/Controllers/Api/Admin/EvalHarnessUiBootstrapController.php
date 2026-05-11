<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * v4.4/W3 Copilot iter 2 finding #2 — Eval Harness UI bootstrap config.
 *
 * GET /api/admin/eval-harness/bootstrap-config
 *
 * Returns the runtime config the cross-mounted `padosoft/eval-harness-ui`
 * SPA needs to render in parity with the iframe predecessor: the
 * metric-label catalogue, polling intervals, locale, and command-palette
 * shortcut. Mirrors the shape the package's blade controller historically
 * injected into a `<script id="eval-harness-ui-bootstrap">` tag — see
 * `vendor/padosoft/eval-harness-ui/src/Http/Controllers/EvalHarnessUiController.php::configPayload()`.
 *
 * The host needs this endpoint because the cross-mount no longer renders
 * inside the package's blade shell; iter 1 hard-coded an empty payload
 * which diverged from `config/eval-harness-ui.php` (R9: docs-must-match-
 * code) and made operators' carefully-tuned `metric_labels` / `polling`
 * settings invisible to the FE (R14: silent functional regression).
 *
 * Pure config read; no DB / no LLM. Mounted behind `auth:sanctum` +
 * `can:eval-harness.viewer` so a viewer / anonymous request 403s before
 * the payload is rendered (mirrors the same Gate guarding every
 * `vendor/padosoft/eval-harness-ui` route).
 *
 * Response shape (200):
 *   {
 *     ui_version: string,
 *     metric_labels: array<string,string>,
 *     tenant_header: string|null,
 *     polling: array<string,int>,
 *     locale: 'en'|'it',
 *     shortcuts: { commandPalette: string }
 *   }
 *
 * `tenant_header` is intentionally returned to the FE for type
 * compatibility with the package's `AppBootstrapConfig` shape, but the
 * cross-mount's `evalHarnessApi.ts` does NOT actually forward the header
 * client-side (see iter 2 finding #1) — the BE
 * `EvalHarnessUiTenantHeader` middleware injects it from
 * `TenantContext::current()` instead.
 */
final class EvalHarnessUiBootstrapController extends Controller
{
    /**
     * Pin this on the controller (NOT in `config/eval-harness-ui.php`)
     * so the version string travels with the integration code path —
     * the package itself stamps `'0.1.0'` as a literal in its
     * `configPayload()`. When AskMyDocs upgrades the package, this
     * constant moves in the same change-set.
     */
    private const UI_VERSION = '0.1.0';

    public function show(): JsonResponse
    {
        // Locale normalisation must accept BOTH POSIX-style (`it_IT`,
        // common in Laravel app()->getLocale() / system locales) AND
        // BCP-47-style (`it-IT`, common in browser Accept-Language and
        // Intl APIs). Splitting on either separator extracts the
        // language subtag, then we lower-case it before the en|it
        // allowlist check. Without the hyphen branch a `it-IT` config
        // value normalised to `it-it` and fell back to `en` — silent
        // i18n regression for any operator who configured their
        // locale the BCP-47 way.
        $rawLocale = config('eval-harness-ui.locale', app()->getLocale());
        $localeParts = is_string($rawLocale) ? preg_split('/[_-]/', $rawLocale, 2) : null;
        $normalisedLocale = is_array($localeParts) && isset($localeParts[0])
            ? strtolower($localeParts[0])
            : 'en';
        $locale = in_array($normalisedLocale, ['en', 'it'], true) ? $normalisedLocale : 'en';

        $tenantHeader = config('eval-harness-ui.tenant_header', null);
        $shortcut = config('eval-harness-ui.assets.command_palette_shortcut', 'mod+k');

        return response()->json([
            'ui_version' => self::UI_VERSION,
            'metric_labels' => (array) config('eval-harness-ui.metric_labels', []),
            'tenant_header' => is_string($tenantHeader) && $tenantHeader !== '' ? $tenantHeader : null,
            'polling' => (array) config('eval-harness-ui.polling', []),
            'locale' => $locale,
            'shortcuts' => [
                'commandPalette' => is_string($shortcut) && trim($shortcut) !== ''
                    ? trim($shortcut)
                    : 'mod+k',
            ],
        ]);
    }
}
