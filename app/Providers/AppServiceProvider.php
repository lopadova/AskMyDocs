<?php

namespace App\Providers;

use App\Console\Commands\KbDeleteCommand;
use App\Console\Commands\KbIngestCommand;
use App\Console\Commands\KbIngestFolderCommand;
use App\Console\Commands\KbPromoteCommand;
use App\Console\Commands\KbRebuildGraphCommand;
use App\Console\Commands\KbValidateCanonicalCommand;
use App\Console\Commands\PruneChatLogsCommand;
use App\Console\Commands\PruneDeletedDocumentsCommand;
use App\Console\Commands\PruneEmbeddingCacheCommand;
use App\Console\Commands\PruneOrphanFilesCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerRateLimiters();
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            PruneEmbeddingCacheCommand::class,
            PruneChatLogsCommand::class,
            PruneDeletedDocumentsCommand::class,
            PruneOrphanFilesCommand::class,
            KbIngestCommand::class,
            KbIngestFolderCommand::class,
            KbDeleteCommand::class,
            // Phase 4 — promotion pipeline
            KbPromoteCommand::class,
            KbValidateCanonicalCommand::class,
            KbRebuildGraphCommand::class,
        ]);
    }

    /**
     * Register the named RateLimiter buckets used by the auth JSON API.
     *
     * `login`  — 5/min per (email + IP). Clears on successful authentication.
     * `forgot` — 3/min per IP. Protects the password-reset broker from
     *            bulk email enumeration probes.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('forgot', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });
    }
}
