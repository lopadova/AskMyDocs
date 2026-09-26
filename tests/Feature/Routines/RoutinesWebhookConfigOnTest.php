<?php

declare(strict_types=1);

namespace Tests\Feature\Routines;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R43 ON-state for `padosoft/laravel-routines`' webhook ingress: with
 * `routines.webhooks.enabled=true` the package's own signed
 * `hooks/routines/{id}` route genuinely mounts. The sibling
 * {@see RoutinesWebhookConfigTest} exercises the default-OFF path this
 * app ships. Same shape as `InvitationsAdminMountingTest` — flip the
 * package's own config key in `getEnvironmentSetUp` for THIS class only.
 */
final class RoutinesWebhookConfigOnTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        // Base TestCase leaves it off (this app's default). Flip it on for
        // THIS class, proving the flag is genuinely wired to the package's
        // own route registration and not just documentation.
        $app['config']->set('routines.webhooks.enabled', true);
    }

    public function test_the_webhook_route_mounts_when_explicitly_re_enabled(): void
    {
        $mounted = false;
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'hooks/routines/{id}') {
                $mounted = true;
                break;
            }
        }

        $this->assertTrue($mounted, 'hooks/routines/{id} must mount when routines.webhooks.enabled=true');
    }
}
