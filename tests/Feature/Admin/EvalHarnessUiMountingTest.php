<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * v4.2/W4 sub-PR 7 — route registration, middleware wiring, and the
 * three independent fences that gate the SPA mount URL.
 *
 * Three observable contracts:
 *
 *   1. Route is registered under the configured prefix and carries
 *      the host-app middleware aliases:
 *        - eval-harness-ui.non-prod      (production guard)
 *        - eval-harness-ui.tenant-header (R30 tenant injection)
 *        - can:eval-harness.viewer       (Gate)
 *
 *   2. With env=true + non-prod + super-admin, the SPA mount URL
 *      returns 200 (HTML response from the package controller).
 *
 *   3. The three fences fail-closed independently:
 *        - env=false (default) → package controller 404
 *        - APP_ENV=production + env=true → host middleware 404
 *        - viewer role + env=true + non-prod → Gate 403
 *
 * The middleware aliases themselves are registered by
 * {@see \App\Providers\EvalHarnessUiIntegrationServiceProvider} — also
 * asserted here so a future refactor that drops the provider trips
 * this test instead of cascading silently.
 */
class EvalHarnessUiMountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_master_switch_default_is_off(): void
    {
        $this->assertFalse(
            (bool) config('eval-harness-ui.enabled'),
            'Default config must keep eval-harness-ui.enabled=false; '.
            'flipping it on for the test config invalidates the disabled-fence assertions.',
        );
    }

    public function test_route_registered_under_configured_prefix_with_host_middleware_aliases(): void
    {
        $route = Route::getRoutes()->getByName('eval-harness-ui.index');

        $this->assertNotNull(
            $route,
            'Expected eval-harness-ui.index to be registered by the package SP.',
        );

        $this->assertStringStartsWith(
            'admin/eval-harness',
            $route->uri(),
            'Route prefix must match config/eval-harness-ui.php::prefix.',
        );

        $middleware = $route->gatherMiddleware();
        $this->assertContains(
            'eval-harness-ui.non-prod',
            $middleware,
            'Route must be gated by the host-app production guard.',
        );
        $this->assertContains(
            'eval-harness-ui.tenant-header',
            $middleware,
            'Route must inject the X-Eval-Harness-Tenant header (R30).',
        );
        $this->assertContains(
            'can:eval-harness.viewer',
            $middleware,
            'Route must be gated by the eval-harness.viewer Gate.',
        );
    }

    public function test_host_middleware_aliases_are_registered(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = app('router');
        $aliases = $router->getMiddleware();

        $this->assertArrayHasKey(
            'eval-harness-ui.non-prod',
            $aliases,
            'Expected eval-harness-ui.non-prod alias to be registered by '.
            'EvalHarnessUiIntegrationServiceProvider.',
        );
        $this->assertSame(
            \App\Http\Middleware\EvalHarnessUiNonProduction::class,
            $aliases['eval-harness-ui.non-prod'],
        );

        $this->assertArrayHasKey(
            'eval-harness-ui.tenant-header',
            $aliases,
            'Expected eval-harness-ui.tenant-header alias to be registered by '.
            'EvalHarnessUiIntegrationServiceProvider.',
        );
        $this->assertSame(
            \App\Http\Middleware\EvalHarnessUiTenantHeader::class,
            $aliases['eval-harness-ui.tenant-header'],
        );
    }

    public function test_default_env_false_returns_404_even_for_super_admin(): void
    {
        // Default config keeps eval-harness-ui.enabled=false, so the
        // package controller's own check fires before the Gate. Even a
        // super-admin sees 404.
        $this->actingAs($this->makeUser('super-admin'));

        $response = $this->get('/'.config('eval-harness-ui.prefix', 'admin/eval-harness'));

        $response->assertStatus(404);
    }

    public function test_production_env_returns_404_even_when_master_switch_is_on(): void
    {
        // R14: route MUST 404 when APP_ENV=production even with
        // EVAL_HARNESS_UI_ENABLED=true. Two fences in series — the
        // host-app middleware fires before the package controller can
        // render the dashboard.
        //
        // Override APP_ENV via the application instance (not just
        // config) so app()->environment('production') answers true.
        $originalEnv = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');
        config(['eval-harness-ui.enabled' => true]);

        try {
            $this->actingAs($this->makeUser('super-admin'));

            $response = $this->get('/'.config('eval-harness-ui.prefix', 'admin/eval-harness'));

            $response->assertStatus(404);
        } finally {
            // R16: restore APP_ENV in afterEach equivalent — leaving
            // APP_ENV=production would cascade-fail every later test
            // in the run.
            $this->app->detectEnvironment(fn () => $originalEnv);
        }
    }

    public function test_viewer_with_env_on_and_non_prod_gets_403(): void
    {
        // Third fence: env=true + non-prod environment + viewer role
        // → the Gate `eval-harness.viewer` rejects with 403 (Laravel's
        // can: middleware behaviour). Proves the Gate is actually
        // wired into the route's middleware stack, not just defined.
        config(['eval-harness-ui.enabled' => true]);

        $this->actingAs($this->makeUser('viewer'));

        $response = $this->get('/'.config('eval-harness-ui.prefix', 'admin/eval-harness'));

        $response->assertStatus(403);
    }

    public function test_super_admin_with_env_on_and_non_prod_gets_200(): void
    {
        // Happy path: all three fences open → package controller
        // renders the SPA bootstrap HTML.
        config(['eval-harness-ui.enabled' => true]);

        $this->actingAs($this->makeUser('super-admin'));

        $response = $this->get('/'.config('eval-harness-ui.prefix', 'admin/eval-harness'));

        $response->assertStatus(200);
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => "Test {$role}",
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole($role);

        return $user->fresh();
    }
}
