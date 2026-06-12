<?php

namespace App\Providers;

use App\Compliance\AskMyDocsUserDataDeleter;
use App\Compliance\AskMyDocsUserDataExporter;
use App\Compliance\RagRefusalQualityMetric;
use App\Console\Commands\AuthGrantCommand;
use App\Console\Commands\CollectionsReevaluateCommand;
use App\Console\Commands\ComplianceDigestQuarterlyCommand;
use App\Console\Commands\EvalNightlyCommand;
use App\Console\Commands\InsightsComputeCommand;
use App\Console\Commands\KbDeleteCommand;
use App\Console\Commands\KbIngestCommand;
use App\Console\Commands\KbIngestFolderCommand;
use App\Console\Commands\KbPromoteCommand;
use App\Console\Commands\KbRebuildGraphCommand;
use App\Console\Commands\KbValidateCanonicalCommand;
use App\Console\Commands\McpConnectCommand;
use App\Console\Commands\PruneAdminCommandAuditCommand;
use App\Console\Commands\PruneAdminCommandNoncesCommand;
use App\Console\Commands\PruneChatLogsCommand;
use App\Console\Commands\PruneDeletedDocumentsCommand;
use App\Console\Commands\PruneEmbeddingCacheCommand;
use App\Console\Commands\PruneNotificationsCommand;
use App\Console\Commands\PruneOrphanFilesCommand;
use App\Connectors\HostIngestionBridge;
use App\Mcp\Adapters\EloquentMcpServerRegistry;
use App\Mcp\Adapters\HostBridge;
use App\Mcp\Adapters\McpToolAuthorizerAdapter;
use App\Models\KnowledgeDocument;
use Padosoft\AskMyDocsMcpPack\Contracts\McpHostBridgeContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpServerRegistryContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use App\Support\TenantContext;
use App\Evidence\AiManagerEvidenceReviewer;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Padosoft\EvidenceRiskReview\Contracts\EvidenceReviewerLlmContract;
use Padosoft\EvidenceRiskReview\Contracts\TenantResolver as EvidenceTenantResolver;
use App\Policies\KnowledgeDocumentPolicy;
use App\Services\Admin\Pdf\PdfRenderer;
use App\Services\Admin\Pdf\PdfRendererFactory;
use App\Services\Kb\Pipeline\PipelineRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Padosoft\PiiRedactorAdmin\Models\PiiRedactorAdminAuditEvent;
use Padosoft\AiActCompliance\BiasMonitoring\Contracts\CohortParityMetric;
use Padosoft\AiActCompliance\DSAR\Contracts\UserDataDeleter;
use Padosoft\AiActCompliance\DSAR\Contracts\UserDataExporter;

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

        // v8.15/W2 — digest card renderers (Discord/Slack/Teams). The registry
        // validates the interface + non-overlapping channel keys at boot (R23).
        $this->app->singleton(\App\Services\Digest\Renderers\DigestRendererRegistry::class, function () {
            return new \App\Services\Digest\Renderers\DigestRendererRegistry([
                new \App\Services\Digest\Renderers\DiscordDigestRenderer(),
                new \App\Services\Digest\Renderers\SlackDigestRenderer(),
                new \App\Services\Digest\Renderers\TeamsDigestRenderer(),
            ]);
        });

        // v4.0/W1.D — TenantContext is request-scoped. Singleton so every
        // service / controller / model trait sees the same instance within
        // one request. Set by ResolveTenant middleware on incoming HTTP;
        // reset between PHPUnit tests via the Application instance lifecycle.
        $this->app->singleton(TenantContext::class);

        // v4.6 connector package extraction — the
        // `padosoft/askmydocs-connector-base` package ships its own
        // `ConnectorRegistry` (singleton-bound by
        // `Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider`)
        // that auto-discovers every connector composer-package via
        // `extra.askmydocs.connectors`. No host-side binding required.

        // v4.6 — alias the package's `TenantContext` to the host's so
        // both surfaces observe the same request-scoped tenant. The
        // package's `BelongsToTenant` trait on
        // `Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation`
        // and the `OAuthCredentialVault` both read the package
        // `TenantContext::current()`; aliasing here guarantees they see
        // exactly what the host's `ResolveTenant` middleware set on
        // `App\Support\TenantContext`. Without this, the package
        // singleton would default to `'default'` and silently break
        // R30 tenant isolation in non-default tenants.
        $this->app->singleton(PackageTenantContext::class, function ($app) {
            $hostCtx = $app->make(TenantContext::class);
            $packageCtx = new PackageTenantContext;
            $packageCtx->set($hostCtx->current());

            return $packageCtx;
        });

        // v4.6 — bind the IoC contract that connector packages call
        // into for ingest dispatch, PII redaction, audit emission,
        // and provider-side deletion. R26 redaction + R30 tenant
        // scoping are enforced inside the bridge.
        $this->app->singleton(
            ConnectorIngestionContract::class,
            HostIngestionBridge::class,
        );

        // v6.0 — AI Act compliance host scaffold. Bind the upstream
        // contracts only when the optional packages are actually installed;
        // this keeps Laravel 13 CI green while the v1 packages catch up.
        if (interface_exists(UserDataExporter::class)) {
            $this->app->singleton(UserDataExporter::class, AskMyDocsUserDataExporter::class);
        }

        if (interface_exists(UserDataDeleter::class)) {
            $this->app->singleton(UserDataDeleter::class, AskMyDocsUserDataDeleter::class);
        }

        if (interface_exists(CohortParityMetric::class)) {
            $this->app->singleton(CohortParityMetric::class, RagRefusalQualityMetric::class);
        }
    }

    public function boot(): void
    {
        // v7.0/W6.3 — bind the three host adapters that satisfy the
        // `padosoft/askmydocs-mcp-pack` contracts. Bindings live in
        // `boot()` (not `register()`) because `bootstrap/providers.php`
        // loads `AppServiceProvider` BEFORE vendor service providers
        // run their `register()`. A bind in `register()` here would
        // be overwritten by the package's `Null*` defaults. Boot-time
        // bindings run AFTER every package's `register()`, so this
        // adapter wiring wins definitively. Singletons are safe —
        // the adapters are stateless wrappers around `AiManager` /
        // Eloquent / Spatie.
        $this->app->singleton(McpHostBridgeContract::class, HostBridge::class);
        $this->app->singleton(McpServerRegistryContract::class, EloquentMcpServerRegistry::class);
        $this->app->singleton(McpToolAuthorizerContract::class, McpToolAuthorizerAdapter::class);

        $this->registerCommands();
        $this->registerRateLimiters();
        $this->registerPolicies();
        $this->registerPiiRedactorAdminGates();
        $this->registerPiiRedactorAdminTenantScope();
        $this->registerPiiRedactorAdminTenantStamping();
        $this->registerEvalHarnessUiGates();
        $this->registerConnectorGates();
        $this->registerMcpGates();
        $this->registerAiActComplianceGates();
        $this->registerTabularReviewGates();
        $this->registerWorkflowGates();
        $this->registerWidgetGates();
        $this->registerEvidenceRiskReviewIntegration();
        $this->registerEvidenceRiskReviewGates();
    }

    /**
     * v8.13/P11 — wire the evidence-risk-review package to the host. Bound in
     * boot() (not register()) so these win over the package's Null* defaults
     * (same ordering rationale as the MCP adapters above).
     */
    private function registerEvidenceRiskReviewIntegration(): void
    {
        // R30 — scope the package's review-log read/write paths to the active
        // host tenant. TenantContext::current() is always set behind
        // tenant.authorize, so reads + writes are always tenant-bound.
        $this->app->singleton(EvidenceTenantResolver::class, function ($app): EvidenceTenantResolver {
            $ctx = $app->make(TenantContext::class);

            return new class($ctx) implements EvidenceTenantResolver
            {
                public function __construct(private readonly TenantContext $ctx) {}

                public function current(): ?string
                {
                    return $this->ctx->current();
                }
            };
        });

        // Optional LLM semantic-review pass over AiManager — only invoked when
        // evidence-risk-review.llm.enabled is true (default-OFF, R43).
        $this->app->singleton(EvidenceReviewerLlmContract::class, AiManagerEvidenceReviewer::class);
    }

    /**
     * v8.13/P11 — Evidence Risk Review admin surface gates. Read gate admits
     * super-admin / admin / dpo; write gate (used by the promote/apply paths)
     * is super-admin / dpo only.
     */
    private function registerEvidenceRiskReviewGates(): void
    {
        Gate::define('viewEvidenceRiskReview', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['super-admin', 'admin', 'dpo']);
        });

        Gate::define('manageEvidenceRiskReview', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['super-admin', 'dpo']);
        });
    }

    /**
     * v6.0 — AI Act compliance admin surface gates.
     */
    private function registerAiActComplianceGates(): void
    {
        Gate::define('viewAiActCompliance', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['super-admin', 'admin', 'dpo']);
        });

        Gate::define('manageAiActCompliance', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['super-admin', 'dpo']);
        });
    }

    /**
     * v4.7/W2 — Wires the Gates that protect the workflows admin
     * surface.
     *
     *   - viewWorkflows     → admit super-admin + admin + viewer
     *     (viewer can browse the catalogue AND manage their OWN
     *      `hidden_workflows` rows — a per-user cosmetic preference,
     *      scoped per-user inside `WorkflowService::hide()` — but
     *      cannot create/update/delete/share workflows or call the
     *      LLM-backed suggester; the controller enforces those
     *      writes via `assertCanCreate()` / `assertCanSuggest()`)
     *   - createWorkflows   → admit super-admin + admin only
     *     (viewer cannot mutate template state)
     *   - suggestWorkflows  → admit super-admin + admin only
     *     (cost-protected; viewer cannot trigger LLM-backed
     *      suggestions even though they're admitted by viewWorkflows
     *      at the HTTP layer)
     *
     * Copilot iter 2: previously the docblock said "viewer is
     * read-only" which was inaccurate — viewers CAN mutate
     * `hidden_workflows` because that table only records their own
     * personal hide-from-my-list markers. The contract above is now
     * explicit.
     */
    private function registerWorkflowGates(): void
    {
        Gate::define('viewWorkflows', function ($user): bool {
            if ($user === null) {
                return false;
            }
            return $user->hasAnyRole(['super-admin', 'admin', 'viewer']);
        });

        Gate::define('createWorkflows', function ($user): bool {
            if ($user === null) {
                return false;
            }
            return $user->hasAnyRole(['super-admin', 'admin']);
        });

        Gate::define('suggestWorkflows', function ($user): bool {
            if ($user === null) {
                return false;
            }
            return $user->hasAnyRole(['super-admin', 'admin']);
        });
    }

    /**
     * Widget admin surface gates (M6).
     *
     * - manageWidgetKeys: super-admin only — rotates/revokes credentials
     *   that gate cross-origin widget access; blast radius too large for
     *   lower roles.
     * - viewWidgetSessions: admin + super-admin — read-only inspection of
     *   active/past widget sessions within tenant.
     */
    private function registerWidgetGates(): void
    {
        Gate::define('manageWidgetKeys', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasRole('super-admin');
        });

        Gate::define('viewWidgetSessions', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['admin', 'super-admin']);
        });
    }

    /**
     * v4.7/W1 — Wires the Gate that protects the tabular-review admin
     * surface. Admits super-admin + admin (full RW within tenant) and
     * viewer (read-only). The controller enforces the read-only side
     * of viewer at the action layer.
     */
    private function registerTabularReviewGates(): void
    {
        Gate::define('viewTabularReviews', function ($user): bool {
            if ($user === null) {
                return false;
            }
            return $user->hasAnyRole(['super-admin', 'admin', 'viewer']);
        });
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

    /**
     * v5.0/W1 — Gate scaffold for MCP registry + audit reads.
     *
     * - manageMcpTools: super-admin only
     * - invokeMcpTools: admin + super-admin
     * - viewMcpAudit: admin + super-admin
     */
    private function registerMcpGates(): void
    {
        Gate::define('manageMcpTools', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasRole('super-admin');
        });

        Gate::define('invokeMcpTools', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['admin', 'super-admin']);
        });

        Gate::define('viewMcpAudit', function ($user): bool {
            if ($user === null) {
                return false;
            }

            return $user->hasAnyRole(['admin', 'super-admin']);
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
            // v8.0/W1.5 — notification_events retention rotation
            // (daily 04:10 via bootstrap/app.php->withSchedule()).
            PruneNotificationsCommand::class,
            // PR14 / Phase I — daily AI insights snapshot.
            InsightsComputeCommand::class,
            // v8.15/W1 — daily engagement snapshot.
            \App\Console\Commands\EngagementComputeCommand::class,
            // v8.15/W2 — rich multi-channel engagement digest.
            \App\Console\Commands\DigestSendCommand::class,
            // v8.15/W3 — in-app digest feed retention sweep.
            \App\Console\Commands\DigestPruneFeedCommand::class,
            // v8.15/W5 — gamification badge awarding (opt-in).
            \App\Console\Commands\GamificationRecomputeCommand::class,
            // v8.0/W6.1 — full tenant collection membership reevaluation.
            CollectionsReevaluateCommand::class,
            // v8.0/W7.4 — consumer MCP debugger bootstrap snippet.
            McpConnectCommand::class,
            // v4.3/W3 — nightly eval-harness regression sentinel.
            EvalNightlyCommand::class,
            // v8.0/W8.5 — quarterly compliance digest (tenant opt-in).
            ComplianceDigestQuarterlyCommand::class,
            // v8.2 — retrieval-quality benchmark + reproducible fixtures.
            \App\Console\Commands\Benchmark\RunBenchmarkCommand::class,
            \App\Console\Commands\Benchmark\MakeBenchmarkFixturesCommand::class,
            // v8.7/W2 — stale-document review sweep + weekly notification digest.
            \App\Console\Commands\KbStaleReviewSweepCommand::class,
            \App\Console\Commands\NotificationsDigestWeeklyCommand::class,
            // v8.7/W5 — Cloud Time Machine archived-version retention.
            \App\Console\Commands\PruneArchivedVersionsCommand::class,
            // v8.9 — UI upload staging buffer retention sweep.
            \App\Console\Commands\PruneStagingBatchesCommand::class,
            // v8.11/P1b — evidence-tier PHP surface (AutoSci #67, R44).
            \App\Console\Commands\KbEvidenceTierCommand::class,
            // v8.11/P2 — auto-wiki graph canonicalization PHP surface (R44).
            \App\Console\Commands\KbWikiLinkCommand::class,
            // v8.11/P3 — concept-page synthesis PHP surface (R44).
            \App\Console\Commands\KbSynthesizeConceptsCommand::class,
            // v8.11/P4 — Auto-Wiki indices PHP surface (R44).
            \App\Console\Commands\KbWikiIndexCommand::class,
            // v8.11/P5 — Auto-Wiki lint PHP surface (R44).
            \App\Console\Commands\KbWikiLintCommand::class,
            // v8.11/P6 — agentic graph-navigation PHP surface (R44).
            \App\Console\Commands\KbWikiNavigateCommand::class,
            // v8.11/P7 — cross-model review PHP surface (R44).
            \App\Console\Commands\KbWikiReviewCommand::class,
            // v8.11/P8 — apply-engine PHP surface (R44).
            \App\Console\Commands\KbApplySuggestionCommand::class,
            // v8.11/P9 — scheduled wiki maintenance PHP surface (R44).
            \App\Console\Commands\KbWikiMaintainCommand::class,
            // v8.11/P10 — Wiki Explorer promote/discard PHP surface (R44).
            \App\Console\Commands\KbWikiPromoteCommand::class,
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
