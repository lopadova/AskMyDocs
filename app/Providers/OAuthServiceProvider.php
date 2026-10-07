<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\OAuthAccessPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

final class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/oauth.php', 'oauth');
    }

    public function boot(): void
    {
        // Named buckets avoid sharing the generic numeric throttle key with
        // unrelated guest endpoints or between browser and backend exchanges.
        foreach (['oauth-authorize', 'oauth-token', 'oauth-revoke'] as $limiter) {
            RateLimiter::for($limiter, static fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));
        }
        Sanctum::authenticateAccessTokensUsing(static fn (PersonalAccessToken $token, bool $valid): bool =>
            app(OAuthAccessPolicy::class)->validate($token, $valid, request()));

        $this->loadRoutesFrom(__DIR__.'/../../routes/oauth.php');
        $this->commands([
            \App\Console\Commands\OAuthClientCommand::class,
            \App\Console\Commands\OAuthDisableClientCommand::class,
            \App\Console\Commands\OAuthPruneCommand::class,
        ]);
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class,
            static fn (\Illuminate\Console\Scheduling\Schedule $schedule) => $schedule->command('oauth:prune')->daily());
    }
}
