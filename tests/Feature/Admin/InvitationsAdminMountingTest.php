<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R43 ON-state for the invitations admin SPA: with `invitations-admin.enabled=true`
 * the self-contained React panel mounts under the configured prefix behind
 * web + auth + the `manageInvitations` gate. The sibling
 * {@see InvitationsAdminDisabledTest} exercises the default-OFF path (clean 404).
 *
 * Same shape as FinOpsAdminMountingTest — the package serves its own prebuilt
 * Blade-rendered SPA shell; the host only supplies the auth + RBAC middleware
 * and the api_base via config/invitations-admin.php.
 */
final class InvitationsAdminMountingTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Base TestCase leaves the SPA off (default). Flip it on for THIS class.
        $app['config']->set('invitations-admin.enabled', true);
    }

    public function test_admin_spa_route_registers_under_the_configured_prefix(): void
    {
        $spa = Route::getRoutes()->getByName('invitations-admin.spa');

        $this->assertNotNull($spa, 'invitations-admin.spa must register when the SPA is enabled.');
        $this->assertStringStartsWith('admin/invitations', $spa->uri());
    }

    public function test_admin_spa_route_is_gated_by_auth_and_the_manage_invitations_gate(): void
    {
        $spa = Route::getRoutes()->getByName('invitations-admin.spa');
        $this->assertNotNull($spa);

        $middleware = $spa->gatherMiddleware();

        // gatherMiddleware() may yield the alias (`auth`) or the resolved FQCN
        // depending on how the stack resolves under Testbench — accept BOTH
        // (mirrors FinOpsAdminMountingTest / AiActComplianceMountingTest).
        $this->assertTrue(
            in_array('auth', $middleware, true)
                || in_array(\Illuminate\Auth\Middleware\Authenticate::class, $middleware, true),
            'The SPA must require authentication (auth alias or Authenticate FQCN).',
        );
        $this->assertContains(
            'can:manageInvitations',
            $middleware,
            'The SPA must be gated by the manageInvitations gate (super-admin + admin).',
        );
    }
}
