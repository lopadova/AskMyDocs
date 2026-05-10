<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EvalHarnessUiNonProduction;
use App\Http\Middleware\EvalHarnessUiTenantHeader;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * v4.2/W4 sub-PR 7 — host-app glue around `padosoft/eval-harness-ui` v1.0.0.
 *
 * Three responsibilities:
 *
 *   1. Register the `eval-harness-ui.non-prod` route middleware alias
 *      consumed by `config/eval-harness-ui.php::route_middleware`. The
 *      alias targets `App\Http\Middleware\EvalHarnessUiNonProduction`
 *      which `abort(404)`s when `APP_ENV=production` — second fence
 *      after the package controller's own `eval-harness-ui.enabled`
 *      check.
 *
 *   2. Register the `eval-harness-ui.tenant-header` route middleware
 *      alias consumed by the same config. The alias targets
 *      `App\Http\Middleware\EvalHarnessUiTenantHeader` which injects
 *      `X-Eval-Harness-Tenant` (configurable name) from the
 *      request-scoped `TenantContext` before the package controller
 *      forwards to the eval-harness API (R30).
 *
 *   3. Document — by separation of concerns — that this provider is
 *      the *only* place where the host-app reaches into the package's
 *      routing surface. Vendor SP stays untouched; everything that
 *      crosses the boundary lives here. Mirrors the
 *      {@see FlowAdminIntegrationServiceProvider} pattern from
 *      sub-PR 6.
 *
 * Why an in-app provider (not AppServiceProvider): the wiring is
 * scoped to a single optional dev-time package
 * (`padosoft/eval-harness-ui` lives in `require-dev`). Keeping the
 * integration isolated makes the dependency graph explicit (search for
 * `EvalHarnessUiIntegrationServiceProvider` finds every touchpoint)
 * and lets future removal be a one-line delete in
 * `bootstrap/providers.php`.
 *
 * The single read-only Gate `eval-harness.viewer` is wired in
 * `AppServiceProvider::registerEvalHarnessUiGates()` so it sits next
 * to the rest of the admin Gate matrix and stays discoverable.
 */
final class EvalHarnessUiIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware(
            'eval-harness-ui.non-prod',
            EvalHarnessUiNonProduction::class,
        );
        $router->aliasMiddleware(
            'eval-harness-ui.tenant-header',
            EvalHarnessUiTenantHeader::class,
        );
    }
}
