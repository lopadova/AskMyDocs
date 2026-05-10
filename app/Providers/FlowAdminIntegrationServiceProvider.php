<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\FlowAdminEnabled;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * v4.2/W4 sub-PR 6 — host-app glue around `padosoft/laravel-flow-admin`.
 *
 * Three responsibilities:
 *
 *   1. Register the `flow-admin.enabled` route middleware alias used by
 *      `config/flow-admin.php::middleware`. Vendor middleware aliases
 *      cannot pierce the package boundary, so the alias must live in
 *      the host-app's router. Without this alias the package would
 *      throw a `BindingResolutionException` on the first request to
 *      a flow-admin route.
 *
 *   2. Register the outer-fence Gate `viewFlowAdmin` consumed by the
 *      `can:viewFlowAdmin` middleware in the same config. Mirrors the
 *      sub-PR 5 pattern (`viewPiiRedactorAdmin`) so the role matrix
 *      stays uniform across admin SPAs.
 *
 *   3. Document — by separation of concerns — that this provider is
 *      the *only* place where the host-app reaches into the package's
 *      routing surface. Vendor SP stays untouched; everything that
 *      crosses the boundary lives here.
 *
 * Why an in-app provider (not AppServiceProvider): the wiring is
 * scoped to a single optional package. Keeping it isolated makes the
 * dependency graph explicit (search for `FlowAdminIntegrationService
 * Provider` finds every flow-admin touchpoint) and lets future
 * removal be a one-line delete in `bootstrap/providers.php`.
 */
final class FlowAdminIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerMiddlewareAlias();
        $this->registerViewGate();
    }

    private function registerMiddlewareAlias(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('flow-admin.enabled', FlowAdminEnabled::class);
    }

    /**
     * Outer-fence Gate consumed by `can:viewFlowAdmin`. Mirrors
     * AppServiceProvider::registerPiiRedactorAdminGates() — Spatie
     * roles back the check; anonymous denies explicitly.
     *
     * The package's 8 authorizer methods
     * (canReplayRun, canCancelRun, canApproveByToken, canRejectByToken,
     * canRetryWebhook, canViewRunDetail, canViewKpis, canViewRuns) are
     * implemented inside {@see \App\Flow\Admin\AskMyDocsFlowAuthorizer},
     * not as Laravel Gates — the package contract is method-based, not
     * Gate-based, and tenant scoping needs the row id which a Laravel
     * Gate closure cannot conveniently receive without re-querying the
     * row a second time inside the closure.
     */
    private function registerViewGate(): void
    {
        Gate::define('viewFlowAdmin', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['super-admin', 'admin', 'dpo']);
        });
    }
}
