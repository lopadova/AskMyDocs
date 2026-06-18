<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\Invite\CompletePendingRedemption;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the invite subsystem's event listeners.
 *
 * Deferred (guest) redemption completes on the first authentication event,
 * whether the user logs in or registers. Both are wired to the same
 * read-and-forget listener (idempotent — see CompletePendingRedemption).
 *
 * The services themselves (CodeGenerator / CodeValidator / RedemptionService /
 * DeferredRedemptionService) are plain constructor-injected classes resolved
 * by the container's auto-wiring — no explicit binding is required.
 */
final class InviteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['events']->listen(Login::class, CompletePendingRedemption::class);
        $this->app['events']->listen(Registered::class, CompletePendingRedemption::class);
    }
}
