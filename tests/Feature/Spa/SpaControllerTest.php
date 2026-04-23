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
        // hook. Mirroring the production wiring (including the guest
        // auth routes that now serve the SPA shell) keeps the test
        // independent of the live routes file and prevents
        // cross-pollution with other Feature tests.
        $router->get('/app/{any?}', \App\Http\Controllers\SpaController::class)
            ->where('any', '.*')
            ->name('spa');

        $router->get('/login', \App\Http\Controllers\SpaController::class)->name('login');
        $router->get('/forgot-password', \App\Http\Controllers\SpaController::class)
            ->name('password.request');
        $router->get('/reset-password/{token}', \App\Http\Controllers\SpaController::class)
            ->name('password.reset');
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

    /**
     * The guest auth entry points (login, forgot-password,
     * reset-password/{token}) must now serve the SPA shell so direct
     * navigation and password-reset email links land on the React
     * router. Without this, a hard refresh on /login would fall back
     * to the (removed) Blade showForm and break the flow.
     */
    public function test_login_route_returns_spa_shell(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('id="root"', false);
    }

    public function test_forgot_password_route_returns_spa_shell(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
        $response->assertSee('id="root"', false);
    }

    public function test_reset_password_with_token_returns_spa_shell(): void
    {
        $response = $this->get('/reset-password/abc123def');

        $response->assertStatus(200);
        $response->assertSee('id="root"', false);
    }

    public function test_reset_password_route_name_is_preserved_for_notification(): void
    {
        // Laravel's default password reset notification generates URLs
        // via route('password.reset', ['token' => …]). Renaming or
        // losing this named route silently breaks the reset-link email.
        $route = Route::getRoutes()->getByName('password.reset');

        $this->assertNotNull($route);
        $this->assertSame('reset-password/{token}', $route->uri());
    }
}
