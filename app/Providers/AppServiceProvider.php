<?php

namespace App\Providers;

use App\Console\Commands\AuthGrantCommand;
use App\Console\Commands\EvalNightlyCommand;
use App\Console\Commands\InsightsComputeCommand;
use App\Console\Commands\KbDeleteCommand;
use App\Console\Commands\KbIngestCommand;
use App\Console\Commands\KbIngestFolderCommand;
use App\Console\Commands\KbPromoteCommand;
use App\Console\Commands\KbRebuildGraphCommand;
use App\Console\Commands\KbValidateCanonicalCommand;
use App\Console\Commands\PruneAdminCommandAuditCommand;
use App\Console\Commands\PruneAdminCommandNoncesCommand;
use App\Console\Commands\PruneChatLogsCommand;
use App\Console\Commands\PruneDeletedDocumentsCommand;
use App\Console\Commands\PruneEmbeddingCacheCommand;
use App\Console\Commands\PruneOrphanFilesCommand;
use App\Connectors\ConnectorRegistry;
use App\Models\KnowledgeDocument;
use App\Policies\KnowledgeDocumentPolicy;
use App\Services\Admin\Pdf\PdfRenderer;
use App\Services\Admin\Pdf\PdfRendererFactory;
use App\Services\Kb\Pipeline\PipelineRegistry;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Padosoft\PiiRedactorAdmin\Models\PiiRedactorAdminAuditEvent;

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

        // T1.4 — KB ingestion pipeline registry. Singleton so the converter +
        // chunker boot cost is paid once per request. Driven by `config/kb-pipeline.php`
        // (see README → "Extending the Ingestion Pipeline").
        $this->app->singleton(PipelineRegistry::class, function ($app) {
            return new PipelineRegistry($app, (array) config('kb-pipeline', []));
        });

        // v4.0/W1.D — TenantContext is request-scoped. Singleton so every
        // service / controller / model trait sees the same instance within
        // one request. Set by ResolveTenant middleware on incoming HTTP;
        // reset between PHPUnit tests via the Application instance lifecycle.
        $this->app->singleton(TenantContext::class);

        // v4.5/W1 — Connector framework registry. Singleton so the
        // built-in + composer auto-discovery cost is paid once per
        // request. R23 — every registered FQCN is validated at boot.
        $this->app->singleton(ConnectorRegistry::class, function ($app) {
            return new ConnectorRegistry($app, (array) config('connectors', []));
        });
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerRateLimiters();
        $this->registerPolicies();
        $this->registerPiiRedactorAdminGates();
        $this->registerPiiRedactorAdminTenantScope();
        $this->registerPiiRedactorAdminTenantStamping();
        $this->registerEvalHarnessUiGates();
        $this->registerConnectorGates();
    }

    /**
     * v4.5/W1 — Wires the Gate that protects the connector admin
     * surface. Super-admin only by default — connectors expose
     * cross-tenant credential vaults + OAuth callback handlers and
     * the blast radius of a misconfigured `admin` granting connector
     * mutations is too large to widen without an ADR.
     */
    private function registerConnectorGates(): void
    {
        Gate::define('manageConnectors', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasRole('super-admin');
        });
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
            // PR13 / Phase H2 — admin audit + nonces rotations.
            PruneAdminCommandAuditCommand::class,
            PruneAdminCommandNoncesCommand::class,
            // PR14 / Phase I — daily AI insights snapshot.
            InsightsComputeCommand::class,
            // v4.3/W3 — nightly eval-harness regression sentinel.
            EvalNightlyCommand::class,
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
     * v4.2/W4 sub-PR 5 — wires the 3 Gates that
     * `padosoft/laravel-pii-redactor-admin` v1.0.2 asks for:
     *
     *   - viewPiiRedactorAdmin       → admin / dpo / super-admin can browse
     *   - detokenisePiiRedactor      → dpo / super-admin can reverse-lookup
     *   - viewPiiRedactorRawSamples  → super-admin only (raw scan samples
     *                                  in detector responses)
     *
     * Spatie role checks back each Gate so Lorenzo's standing 4-role
     * matrix (super-admin / admin / editor / viewer) keeps working —
     * `dpo` is added to the seeder in the same PR. Anonymous requests
     * (no $user) deny by returning false explicitly; relying on Laravel's
     * default null-coalesce is also safe but the explicit return keeps
     * the intent obvious to future readers.
     */
    private function registerPiiRedactorAdminGates(): void
    {
        Gate::define('viewPiiRedactorAdmin', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['super-admin', 'dpo', 'admin']);
        });

        Gate::define('detokenisePiiRedactor', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['super-admin', 'dpo']);
        });

        Gate::define('viewPiiRedactorRawSamples', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasRole('super-admin');
        });
    }

    /**
     * v4.2/W4 sub-PR 5 — enforce tenant isolation on package audit-event
     * reads (R30). The vendor model does not use BelongsToTenant, so we add
     * an app-level global scope keyed to the active TenantContext.
     *
     * Backfill/admin flows that intentionally scan multiple tenants can opt
     * out with `withoutGlobalScope('tenant')`.
     */
    private function registerPiiRedactorAdminTenantScope(): void
    {
        PiiRedactorAdminAuditEvent::addGlobalScope('tenant', function ($query): void {
            $query->where('tenant_id', app(TenantContext::class)->current());
        });
    }

    /**
     * v4.2/W4 sub-PR 5 — stamps the active tenant_id on every
     * PiiRedactorAdminAuditEvent before insert (R31).
     *
     * The package model is single-tenant by design (vendor `$fillable`
     * intentionally excludes `tenant_id`), so we attach a `creating`
     * Eloquent observer that mutates the column directly — the column
     * was added by `2026_05_10_021617_add_tenant_id_to_pii_redactor_
     * admin_audit_events_table.php`.
     *
     * Bypassing $fillable is safe here: `tenant_id` is server-derived
     * from the request-scoped TenantContext singleton, never user input.
     * If a row arrives with an explicit tenant_id already set (e.g.
     * from an admin tool inserting a backfill row), we don't overwrite
     * it — the observer sets only when the field is null/empty.
     */
    private function registerPiiRedactorAdminTenantStamping(): void
    {
        PiiRedactorAdminAuditEvent::creating(function (PiiRedactorAdminAuditEvent $event): void {
            $current = $event->getAttribute('tenant_id');
            if (is_string($current) && $current !== '') {
                return;
            }

            $event->setAttribute('tenant_id', app(TenantContext::class)->current());
        });
    }

    /**
     * v4.2/W4 sub-PR 7 — wires the single read-only Gate that
     * `padosoft/eval-harness-ui` v1.0.0 asks for via the
     * `can:eval-harness.viewer` middleware in
     * `config/eval-harness-ui.php::route_middleware`.
     *
     * The eval dashboard is read-only by design — there are no
     * mutation endpoints — so a single viewer Gate is enough. Spatie
     * roles back the check; anonymous denies explicitly.
     *
     * Allowlist: super-admin, admin, dpo, editor.
     * - super-admin / admin keep parity with every other admin SPA
     *   (PII Redactor, Flow Admin, KB explorer).
     * - dpo can audit RAG eval reports for compliance / GDPR
     *   evidence — same audience that needs Flow Admin webhook
     *   visibility.
     * - editor can verify their canonical-doc edits did not regress
     *   factuality / accuracy — eval reports are the closing-of-the-loop
     *   for the canonical compilation workflow editors own.
     *
     * Denylist: viewer (read-only across content but explicitly excluded
     * from infrastructure dashboards) and anonymous.
     */
    private function registerEvalHarnessUiGates(): void
    {
        Gate::define('eval-harness.viewer', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['super-admin', 'admin', 'dpo', 'editor']);
        });
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
