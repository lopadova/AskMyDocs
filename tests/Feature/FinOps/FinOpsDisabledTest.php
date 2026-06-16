<?php

declare(strict_types=1);

namespace Tests\Feature\FinOps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R43 OFF-state: with both master switches off, the core API AND the admin SPA
 * degrade to a clean 404 — the routes are never registered, so a disabled
 * subsystem is indistinguishable from one that never existed (R14), NEVER a 500.
 */
final class FinOpsDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Base TestCase forces the core ON for matrix coverage; flip BOTH
        // master switches off here to exercise the disabled-degrade path.
        $app['config']->set('ai-finops.enabled', false);
        $app['config']->set('ai-finops-admin.enabled', false);
    }

    public function test_core_api_is_absent_and_404s_when_disabled(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('ai-finops.settings.index'),
            'Core finops routes must NOT register when ai-finops.enabled=false.',
        );

        $this->getJson('/api/admin/ai-finops/settings')->assertNotFound();
    }

    public function test_admin_spa_is_absent_and_404s_when_disabled(): void
    {
        $this->assertNull(
            Route::getRoutes()->getByName('ai-finops-admin.home'),
            'Admin SPA route must NOT register when ai-finops-admin.enabled=false.',
        );

        $this->get('/admin/ai-finops')->assertNotFound();
    }
}
