<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * v4.2/W4 sub-PR 5 — proves the disabled-by-default contract.
 *
 * Sibling to PiiRedactorAdminMountingTest, which flips the SPA on
 * via defineEnvironment(). This class deliberately leaves the
 * default config (enabled=false from config/pii-redactor-admin.php)
 * so we get evidence that the fail-closed posture is wired
 * correctly: no routes registered, no surface area exposed.
 *
 * This also defends R14: a request to /admin/pii-redactor under
 * disabled config must NOT silently 200; the absent route → 404 is
 * the correct semantic.
 */
class PiiRedactorAdminDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_pii_redactor_admin_routes_when_master_switch_off(): void
    {
        // Sanity check: confirm we're actually in the disabled state
        // before the assertions below mean anything.
        $this->assertFalse(
            (bool) config('pii-redactor-admin.enabled'),
            'Default test config must keep pii-redactor-admin.enabled=false; '.
            'flipping it on for this test class would invalidate the assertion.',
        );

        $names = collect(Route::getRoutes())
            ->map(fn ($route) => (string) $route->getName())
            ->filter(fn (string $name) => str_starts_with($name, 'pii-redactor-admin'))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $names,
            'Expected zero pii-redactor-admin routes when the master switch is off; '.
            'got ['.implode(',', $names).'].',
        );
    }
}
