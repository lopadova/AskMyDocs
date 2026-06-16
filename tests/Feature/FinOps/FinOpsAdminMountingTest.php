<?php

declare(strict_types=1);

namespace Tests\Feature\FinOps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R43 ON-state for the FinOps admin SPA: with `ai-finops-admin.enabled=true` the
 * React panel mounts under the configured prefix behind web + auth + the
 * `viewAiFinOps` gate. The sibling {@see FinOpsDisabledTest} exercises the
 * default-off path (clean 404).
 */
final class FinOpsAdminMountingTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Base TestCase leaves the SPA off (default). Flip it on for THIS class.
        $app['config']->set('ai-finops-admin.enabled', true);
    }

    public function test_admin_spa_route_registers_under_the_configured_prefix(): void
    {
        $home = Route::getRoutes()->getByName('ai-finops-admin.home');

        $this->assertNotNull($home, 'ai-finops-admin.home must register when the SPA is enabled.');
        $this->assertStringStartsWith('admin/ai-finops', $home->uri());
    }

    public function test_admin_spa_route_is_gated_by_auth_and_the_view_finops_gate(): void
    {
        $home = Route::getRoutes()->getByName('ai-finops-admin.home');
        $this->assertNotNull($home);

        $middleware = $home->gatherMiddleware();

        $this->assertContains('auth', $middleware, 'The SPA must require authentication.');
        $this->assertContains(
            'can:viewAiFinOps',
            $middleware,
            'The SPA must be gated by the viewAiFinOps gate (super-admin + admin).',
        );
    }
}
