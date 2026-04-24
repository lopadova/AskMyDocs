<?php

namespace App\Providers;

use App\Console\Commands\AuthGrantCommand;
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
use App\Models\KnowledgeDocument;
use App\Policies\KnowledgeDocumentPolicy;
use App\Services\Admin\Pdf\PdfRenderer;
use App\Services\Admin\Pdf\PdfRendererFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PR11 / Phase G4 — PDF rendering strategy. The interface is
        // resolved through {@see PdfRendererFactory} so the controller
        // can type-hint `PdfRenderer` and let the container pick the
        // concrete class. Default is 'disabled' — switching to
        // 'dompdf' or 'browsershot' requires the matching suggest
        // package (see composer.json). Bound in register() (not boot)
        // because Laravel's HTTP kernel may resolve controller
        // dependencies before boot() on a warm container.
        $this->app->bind(PdfRenderer::class, fn () => PdfRendererFactory::resolve());
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerRateLimiters();
        $this->registerPolicies();
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
            // PR3 — RBAC
            AuthGrantCommand::class,
        ]);
    }

    /**
     * Register authorization policies. This project's bootstrap/providers.php
     * uses explicit registration (no package auto-discovery), so policies
     * need an explicit Gate::policy mapping here — Laravel 13's convention
     * auto-discovery only kicks in when the provider list is scanned.
     */
    private function registerPolicies(): void
    {
        Gate::policy(KnowledgeDocument::class, KnowledgeDocumentPolicy::class);
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
