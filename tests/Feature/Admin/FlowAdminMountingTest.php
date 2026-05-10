<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Padosoft\LaravelFlowAdmin\Authorizers\DenyAllAuthorizer;
use Padosoft\LaravelFlowAdmin\Contracts\ActionAuthorizer;
use Tests\TestCase;

/**
 * v4.2/W4 sub-PR 6 — Route registration + middleware wiring + DI binding.
 *
 * Observable contracts:
 *
 *   1. With `flow-admin.enabled=true`, every package route resolves
 *      under the configured prefix and carries:
 *        - `flow-admin.enabled`     (host-app master switch middleware)
 *        - `can:viewFlowAdmin`      (host-app outer-fence Gate)
 *      Defence-in-depth: even if the SP changes route declarations in
 *      a future minor, the host-app middleware stack still gates HTTP.
 *
 *   2. The `ActionAuthorizer` contract resolves to {@see
 *      \App\Flow\Admin\AskMyDocsFlowAuthorizer} — the host-app
 *      implementation that maps mutations to Spatie roles + tenant
 *      scoping. The vendor `DenyAllAuthorizer` MUST NOT win.
 *
 *   3. With `flow-admin.enabled=false` (default), a request to the
 *      configured prefix `abort(404)`s — no body, no JSON shape,
 *      indistinguishable from a route that never existed (R14).
 *
 *   4. The host-app `flow-admin.enabled` middleware alias is
 *      registered, so `config('flow-admin.middleware')` can reference
 *      it without a `BindingResolutionException`.
 */
class FlowAdminMountingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Flip the cockpit on for THIS class only. Sibling
     * FlowAdminDisabledTest exercises the default-off path.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('flow-admin.enabled', true);
        $app['config']->set('flow-admin.prefix', 'admin/flows');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_overview_route_registers_under_configured_prefix(): void
    {
        $overview = Route::getRoutes()->getByName('flow-admin.overview');

        $this->assertNotNull(
            $overview,
            'Expected flow-admin.overview to be registered when enabled.',
        );

        $this->assertStringStartsWith('admin/flows', $overview->uri());
    }

    public function test_every_package_route_carries_master_switch_and_view_gate_middleware(): void
    {
        $names = [
            'flow-admin.overview',
            'flow-admin.runs.index',
            'flow-admin.approvals.index',
            'flow-admin.outbox.index',
            'flow-admin.definitions.index',
            'flow-admin.settings.index',
            'flow-admin.api.search',
            'flow-admin.api.live',
            'flow-admin.theme.toggle',
        ];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route '{$name}' must be registered when enabled.");

            $middleware = $route->gatherMiddleware();
            $this->assertContains(
                'flow-admin.enabled',
                $middleware,
                "Route '{$name}' must be gated by the master-switch middleware.",
            );
            $this->assertContains(
                'can:viewFlowAdmin',
                $middleware,
                "Route '{$name}' must be gated by the can:viewFlowAdmin outer-fence.",
            );
        }
    }

    public function test_action_authorizer_binding_resolves_to_host_app_implementation(): void
    {
        $resolved = app(ActionAuthorizer::class);

        $this->assertInstanceOf(
            \App\Flow\Admin\AskMyDocsFlowAuthorizer::class,
            $resolved,
            'Expected the host-app authorizer to be bound — the vendor DenyAllAuthorizer must not win.',
        );

        $this->assertNotInstanceOf(
            DenyAllAuthorizer::class,
            $resolved,
            'DenyAllAuthorizer would silently lock the cockpit even for super-admin.',
        );
    }

    public function test_master_switch_middleware_alias_is_registered(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = app('router');
        $aliases = $router->getMiddleware();

        $this->assertArrayHasKey(
            'flow-admin.enabled',
            $aliases,
            'Expected `flow-admin.enabled` middleware alias to be registered '.
            'so config/flow-admin.php can reference it.',
        );
        $this->assertSame(
            \App\Http\Middleware\FlowAdminEnabled::class,
            $aliases['flow-admin.enabled'],
        );
    }
}
