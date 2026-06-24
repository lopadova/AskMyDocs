<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R43 OFF-state for the invitations admin SPA: with `invitations-admin.enabled`
 * false (the default), the package registers NO route, so `/admin/invitations`
 * degrades to a clean 404 — a disabled panel is indistinguishable from one that
 * never existed (R14), NEVER a 500. The sibling {@see InvitationsAdminMountingTest}
 * exercises the enabled path (mounted + gated).
 *
 * NOTE: the CORE invitations API (PR #363) is independent — its routes stay
 * registered (invitations.routes.enabled default true); only the ADMIN SPA mount
 * is gated by this flag.
 */
final class InvitationsAdminDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Explicit OFF (also the default) to exercise the disabled-degrade path.
        $app['config']->set('invitations-admin.enabled', false);
    }

    public function test_admin_spa_is_absent_and_404s_when_disabled(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('invitations-admin.spa'),
            'Admin SPA route must NOT register when invitations-admin.enabled=false.',
        );

        $this->get('/admin/invitations')->assertNotFound();
    }
}
