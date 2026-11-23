<?php

declare(strict_types=1);

namespace App\Pact\Native;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use PactLaravel\Contracts\IdentityResolver;
use PactLaravel\Models\Task;
use PactLaravel\OAuth\PeerMiddleware;

final class ClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The host already owns /oauth and the unprefixed oauth_* tables.
        Passport::ignoreRoutes();
        $connection = $this->app['config']->get('database.default');
        $settings = $this->app['config']->get('database.connections.'.$connection);
        $this->app['config']->set('database.connections.pact_passport', [
            ...$settings,
            'prefix' => ($settings['prefix'] ?? '').'pact_passport_',
            'prefix_indexes' => true,
        ]);
        $this->app->bind(IdentityResolver::class, SubjectResolver::class);
        $this->app->extend(PeerMiddleware::class, static fn (PeerMiddleware $peer) => new ScopedPeerAccess($peer));
    }

    public function boot(): void
    {
        Task::creating(static function (Task $task): void {
            if (config('pact.role') === 'client') {
                app(TaskAccess::class)->bindGeneration($task);
            }
        });
        if (! config('pact.enabled') || config('pact.role') !== 'client') {
            return;
        }
        View::composer('pact::layout', static fn ($view) => $view->with('pactStyleNonce', Vite::cspNonce()));
        View::composer('pact::consent', static fn ($view) => $view->with('pactSubject', request()->attributes->get(Subject::class)));
        Route::post(trim((string) config('pact.prefix', 'pact'), '/').'/oauth/select-project', [ConsentScope::class, 'select'])
            ->middleware(['web', 'auth:web', 'throttle:20,1'])
            ->name('pact.oauth.select-project');
    }
}
