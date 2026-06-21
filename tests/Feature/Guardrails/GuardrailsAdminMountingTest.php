<?php

declare(strict_types=1);

namespace Tests\Feature\Guardrails;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * v8.19/W3 — the laravel-ai-guardrails-admin SPA, mounted at /admin/ai-guardrails.
 *
 * R43 BOTH states:
 *   - OFF (default): the `guardrails-admin.enabled` middleware 404s the route —
 *     a clean 404, never a 500 (the cockpit is dark on a fresh deploy).
 *   - ON: the React panel mounts under the prefix behind web + auth +
 *     `can:viewAiGuardrails` — guest redirected, viewer 403, admin 200.
 *
 * The class flips the SPA ON (base TestCase leaves it off); the OFF assertion
 * overrides the flag per-test.
 */
final class GuardrailsAdminMountingTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Base TestCase leaves the SPA off (default). Flip it on for THIS class.
        $app['config']->set('ai-guardrails-admin.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_route_registers_under_the_configured_prefix_when_enabled(): void
    {
        $panel = Route::getRoutes()->getByName('ai-guardrails-admin.panel');

        $this->assertNotNull($panel, 'ai-guardrails-admin.panel must register when the SPA is enabled.');
        $this->assertStringStartsWith('admin/ai-guardrails', $panel->uri());
    }

    public function test_route_is_gated_by_the_master_switch_auth_and_the_view_gate(): void
    {
        $panel = Route::getRoutes()->getByName('ai-guardrails-admin.panel');
        $this->assertNotNull($panel);

        $middleware = $panel->gatherMiddleware();

        $this->assertContains('guardrails-admin.enabled', $middleware, 'The SPA must carry the master-switch 404 gate.');
        $this->assertTrue(
            in_array('auth', $middleware, true)
                || in_array(\Illuminate\Auth\Middleware\Authenticate::class, $middleware, true),
            'The SPA must require authentication.',
        );
        $this->assertContains('can:viewAiGuardrails', $middleware, 'The SPA must be gated by viewAiGuardrails.');
    }

    public function test_disabled_flag_returns_a_clean_404(): void
    {
        // R43 OFF-state: flip the master switch off → the enabled middleware 404s.
        config()->set('ai-guardrails-admin.enabled', false);

        $this->actingAs($this->userWithRole('admin'))
            ->get('/admin/ai-guardrails')
            ->assertNotFound();
    }

    public function test_viewer_is_forbidden_when_enabled(): void
    {
        $this->actingAs($this->userWithRole('viewer'))
            ->get('/admin/ai-guardrails')
            ->assertForbidden();
    }

    public function test_admin_can_open_the_panel_when_enabled(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get('/admin/ai-guardrails')
            ->assertOk();
    }
}
