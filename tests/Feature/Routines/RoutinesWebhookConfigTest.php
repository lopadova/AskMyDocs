<?php

declare(strict_types=1);

namespace Tests\Feature\Routines;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Subagent review, PR #512 — should-fix: `padosoft/laravel-routines`'
 * own webhook ingress (`hooks/routines/{id}`) defaults to mounted in the
 * package itself. This app turns it off (`config/routines.php`
 * `webhooks.enabled`, ADR 0033 §5) because nothing here ever creates a
 * `trigger_kind=webhook` routine. Proves the OFF state under this app's
 * default config; the sibling {@see RoutinesWebhookConfigOnTest} proves
 * flipping the flag genuinely mounts it (R43 — both states tested), so
 * the allow-list entry in {@see \Tests\Architecture\RouteExposureTest} is
 * not describing dead config.
 */
final class RoutinesWebhookConfigTest extends TestCase
{
    private function webhookRouteIsRegistered(): bool
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'hooks/routines/{id}') {
                return true;
            }
        }

        return false;
    }

    public function test_the_webhook_route_is_not_mounted_under_this_apps_default_config(): void
    {
        $this->assertFalse($this->webhookRouteIsRegistered());
    }
}
