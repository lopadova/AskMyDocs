<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v4.2/W4 sub-PR 7 — production fence for the eval-harness-ui SPA.
 *
 * The eval dashboard is an internal QA / engineering surface. Even if
 * an operator flips `EVAL_HARNESS_UI_ENABLED=true` by accident on a
 * production deploy, this middleware short-circuits the request with
 * a clean 404 — the route appears not to exist (R14 — correct semantic
 * for a disabled subsystem).
 *
 * Two fences in series:
 *   1. The package controller's own check (`abort(404)` when
 *      `eval-harness-ui.enabled` is false).
 *   2. This middleware (`abort(404)` when `APP_ENV=production`).
 *
 * Either fire alone is enough to return 404; both must be open for the
 * SPA to render.
 *
 * Registered as a route middleware alias `eval-harness-ui.non-prod` by
 * {@see \App\Providers\EvalHarnessUiIntegrationServiceProvider} so the
 * vendor's `routes/web.php` (read indirectly via
 * `config('eval-harness-ui.route_middleware')`) can reference it.
 */
final class EvalHarnessUiNonProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production')) {
            abort(404);
        }

        return $next($request);
    }
}
