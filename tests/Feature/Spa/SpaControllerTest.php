<?php

namespace Tests\Feature\Spa;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Smoke tests for the React SPA entry point. The controller returns a
 * bare blade view whose job is to host `<div id="root">` and hand
 * control to Vite's `@vite` directive. We don't exercise Vite here —
 * just verify the HTML shell is reachable, names the root mount point,
 * and that the catch-all passes through nested paths unchanged.
 */
class SpaControllerTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        // TestCase registers routes through Testbench's `defineRoutes`
        // hook. Mirroring the production wiring keeps the test
        // independent of the live routes file and prevents
        // cross-pollution with other Feature tests.
        $router->get('/app/{any?}', \App\Http\Controllers\SpaController::class)
            ->where('any', '.*')
            ->name('spa');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Make the Vite helpers a no-op so the view renders without
        // triggering a manifest read during testing. TestCase's
        // `getEnvironmentSetUp` wires `view.paths` to the project's
        // `resources/views` directory so `view('app')` resolves.
        $this->withoutVite();
    }

    public function test_app_route_returns_200_and_contains_root_mount(): void
    {
        $response = $this->get('/app');

        $response->assertStatus(200);
        $response->assertSee('id="root"', false);
    }

    public function test_nested_app_path_resolves_to_same_shell(): void
    {
        $response = $this->get('/app/admin/users');

        $response->assertStatus(200);
        $response->assertSee('id="root"', false);
    }

    public function test_spa_route_is_registered_with_catch_all(): void
    {
        $route = Route::getRoutes()->getByName('spa');

        $this->assertNotNull($route);
        $this->assertSame('app/{any?}', $route->uri());
    }
}
