<?php

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        // Manual registration (instead of getPackageProviders) avoids
        // ProviderRepository's is_writable() check that fails on Windows
        // for paths containing spaces.

        // laravel/ai SDK + padosoft/laravel-ai-regolo extension —
        // registered first so the SDK's MultipleInstanceManager + the
        // 'ai.provider.regolo' container binding exist before
        // App\Providers\AiServiceProvider or any RegoloProvider call
        // tries to resolve them.
        $app->register(\Laravel\Ai\AiServiceProvider::class);
        $app->register(\Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider::class);

        // padosoft/laravel-pii-redactor — registered explicitly because
        // Testbench skips Laravel's package-discovery cache and the
        // `extra.laravel.providers` auto-discovery hook only runs in
        // app boot (bootstrap/cache/packages.php). Without this,
        // `RedactorEngine` resolution throws BindingResolutionException
        // in feature tests for the `redact-chat-pii` middleware.
        $app->register(\Padosoft\PiiRedactor\PiiRedactorServiceProvider::class);

        // v4.2/W4 sub-PR 5 — padosoft/laravel-pii-redactor-admin SP.
        // Same explicit-registration reason as PiiRedactor above.
        // Routes are only registered when
        // `pii-redactor-admin.enabled=true` (default false), so the
        // bare boot is a safe no-op for tests that don't opt in.
        $app->register(\Padosoft\PiiRedactorAdmin\PiiRedactorAdminServiceProvider::class);

        // v4.2/W2 — laravel-flow saga engine. Registered before
        // App\Providers\FlowServiceProvider so the FlowEngine singleton
        // is available when the in-app definition registry boots.
        $app->register(\Padosoft\LaravelFlow\LaravelFlowServiceProvider::class);

        // v4.2/W4 sub-PR 6 — padosoft/laravel-flow-admin v1.0.0 + the
        // host-app integration provider. The package SP unconditionally
        // registers routes; `flow-admin.enabled=false` (the default) is
        // enforced by the `flow-admin.enabled` middleware aliased by
        // FlowAdminIntegrationServiceProvider. The integration SP MUST
        // be registered AFTER the package SP so the alias is available
        // before the package's route group is matched on the first
        // request, and so the AskMyDocsFlowAuthorizer binding overrides
        // the vendor's DenyAllAuthorizer.
        $app->register(\Padosoft\LaravelFlowAdmin\FlowAdminServiceProvider::class);
        $app->register(\App\Providers\FlowAdminIntegrationServiceProvider::class);
        // v8.0/W1.2 — notification dispatch pipeline (ChannelRegistry
        // singleton + Event::listen wiring for the 4 BaseNotificationEvent
        // subclasses). Same explicit-registration reason as the other
        // host providers above (Testbench skips bootstrap/providers.php).
        $app->register(\App\Providers\NotificationServiceProvider::class);
        // M4 — KITT Widget AiTool registry (R23). Registered after
        // AppServiceProvider so ChatRetrievalService is bound for
        // SearchKnowledgeBaseTool.
        $app->register(\App\Providers\WidgetServiceProvider::class);

        // v4.2/W3 — padosoft/eval-harness service provider. Manual
        // registration because Testbench skips package auto-discovery.
        // Provides EvalEngine, MetricResolver, YamlDatasetLoader, and
        // the eval-harness:run / eval-harness:adversarial commands.
        $app->register(\Padosoft\EvalHarness\EvalHarnessServiceProvider::class);

        // v4.2/W4 sub-PR 7 — padosoft/eval-harness-ui v1.0.0 + the
        // host-app integration provider. The package SP unconditionally
        // registers routes; the package CONTROLLER aborts 404 when
        // `eval-harness-ui.enabled=false` (the default), and the
        // host-app `eval-harness-ui.non-prod` middleware aborts 404
        // when APP_ENV=production. Either fence alone is enough; both
        // must be open for the SPA to render. The integration SP MUST
        // be registered AFTER the package SP so the alias is available
        // before any request matches the package's route group.
        $app->register(\Padosoft\EvalHarnessUi\EvalHarnessUiServiceProvider::class);
        $app->register(\App\Providers\EvalHarnessUiIntegrationServiceProvider::class);

        // v6.0 — padosoft/laravel-ai-act-compliance service provider.
        // Manual registration parallels every other vendor package above
        // because Testbench skips Laravel's package-discovery cache. The
        // SP calls loadMigrationsFrom() (consent_records,
        // risk_register_entries, dsar_requests, etc.) and aliases the
        // `ai-act.*` middleware on the router. The host wires the host-
        // facing `ai.disclosure` / `ai.consent` aliases in
        // bootstrap/app.php (mirrored further down this method for
        // Testbench).
        $app->register(\Padosoft\AiActCompliance\AiActComplianceServiceProvider::class);
        // v6.0 — companion admin SPA SP. Registered here because the
        // admin route group depends on the parent SP's middleware aliases
        // already being on the router.
        $app->register(\Padosoft\AiActComplianceAdmin\AiActComplianceAdminServiceProvider::class);

        // v4.6 — connector framework + 7 standalone connector packages.
        // Manual registration parallels every other vendor package above
        // because Testbench skips Laravel's package-discovery cache. The
        // base SP binds ConnectorRegistry as a singleton + auto-discovers
        // every package's connectors via composer.lock's
        // `extra.askmydocs.connectors`. Each per-connector SP registers
        // its own connector FQCN; the base SP boots first so the
        // ConnectorRegistry exists when the connector SPs' boot()
        // methods reach for it. AppServiceProvider runs LATER in the
        // list to rebind ConnectorIngestionContract onto
        // HostIngestionBridge (overriding the package default
        // NullConnectorIngestionContract).
        $app->register(\Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider::class);
        $app->register(\Padosoft\AskMyDocsConnectorGoogleDrive\GoogleDriveServiceProvider::class);
        $app->register(\Padosoft\AskMyDocsConnectorNotion\NotionServiceProvider::class);
        $app->register(\Padosoft\AskMyDocsConnectorEvernote\EvernoteServiceProvider::class);
        $app->register(\Padosoft\AskMyDocsConnectorFabric\FabricServiceProvider::class);
        $app->register(\Padosoft\AskMyDocsConnectorOneDrive\OneDriveServiceProvider::class);
        $app->register(\Padosoft\AskMyDocsConnectorConfluence\ConfluenceServiceProvider::class);
        $app->register(\Padosoft\AskMyDocsConnectorJira\JiraServiceProvider::class);
        // v8.17 — IMAP connector (first credential-based). Its SP binds
        // ImapClientFactoryInterface → ImapClientFactory and merges the xoauth2
        // provider config, so the registry can instantiate ImapConnector.
        $app->register(\Padosoft\AskMyDocsConnectorImap\ImapServiceProvider::class);
        // v8.13/P11 — Evidence Risk Review core package. Registered so its HTTP
        // API mounts (api.enabled=true via the host config loaded in
        // getEnvironmentSetUp) and the AdminAuthorizationMatrix can verify the
        // secured `/api/admin/evidence-risk-review/*` group. The `-admin`
        // package is dont-discovered (AskMyDocs renders the admin natively).
        $app->register(\Padosoft\EvidenceRiskReview\EvidenceRiskReviewServiceProvider::class);

        // v8.16 — padosoft/laravel-ai-finops core + companion admin SPA.
        // Manual registration parallels every other vendor package above
        // because Testbench skips Laravel's package-discovery cache. The core
        // SP loadMigrationsFrom()s the ai_finops_* tables, binds UsageRecorder /
        // PricingRegistry, and (config-gated) registers the metering hook on the
        // laravel/ai lifecycle + the secured route group. The admin SP only
        // registers its SPA route when `ai-finops-admin.enabled=true` (default
        // false — bare boot is a safe no-op for tests that don't opt in).
        $app->register(\Padosoft\LaravelAiFinOps\LaravelAiFinOpsServiceProvider::class);
        $app->register(\Padosoft\LaravelAiFinOpsAdmin\LaravelAiFinOpsAdminServiceProvider::class);

        // v8.19 — padosoft/laravel-ai-guardrails core. Manual registration
        // parallels every other vendor package (Testbench skips package
        // discovery). The SP wires the screen/sanitize/firewall/HITL controls,
        // the append-only stores, and (config-gated) the secured HTTP API route
        // group consumed by the guardrails-admin SPA (W3). The host config
        // override below applies the R32 auth stack + database stores.
        $app->register(\Padosoft\AiGuardrails\AiGuardrailsServiceProvider::class);
        // v8.19/W3 — guardrails-admin SPA. The package mounts its catch-all SPA
        // route unconditionally; the host `guardrails-admin.enabled` middleware
        // (config below) gates it default-OFF. Registered here so the mounting
        // test can flip the flag on and assert the wired-and-secured route.
        $app->register(\Padosoft\LaravelAiGuardrailsAdmin\LaravelAiGuardrailsAdminServiceProvider::class);

        // padosoft/laravel-invitations — invite-by-code engine. Manual
        // registration parallels every other vendor package (Testbench skips
        // package discovery). The SP loadMigrationsFrom()s the 9 invite tables,
        // binds the vendor-neutral TenantResolver default + tags the
        // SpatiePermissionProvisioner, and (config-gated) loads the invite route
        // file. Registered BEFORE AppServiceProvider so the host's boot()-time
        // overrides — TenantResolver → TenantContext, ProjectMembershipProvisioner
        // added to the tag, manageInvitations gate — win definitively.
        $app->register(\Padosoft\Invitations\InvitationsServiceProvider::class);
        // padosoft/laravel-invitations-admin SPA. Same explicit-registration
        // reason as the other vendor SPs. Routes register only when
        // invitations-admin.enabled=true (default false), so the bare boot is a
        // safe no-op for tests that don't opt in; the mounting test flips it on
        // in its own getEnvironmentSetUp to prove the wired-and-secured route.
        $app->register(\Padosoft\Invitations\Admin\InvitationsAdminServiceProvider::class);

        $app->register(\App\Providers\AiServiceProvider::class);
        $app->register(\App\Providers\ChatLogServiceProvider::class);
        $app->register(\App\Providers\AppServiceProvider::class);
        // v4.3/W1 sub-PR 4.5 — comprehensive PII boundary coverage.
        // The RedactorEngine binding is provided by the package's own
        // PiiRedactorServiceProvider (registered higher up in this list);
        // ordering here is for consistency with the AppServiceProvider
        // group rather than a hard binding-resolution dependency.
        $app->register(\App\Providers\PiiBoundaryCoverageServiceProvider::class);
        // v8.9 — KB UI upload progress wiring (KnowledgeDocument observer +
        // the 3 queue-event listeners that drive batch-item status). Registered
        // after AppServiceProvider so KbUploadStagingService's deps (TenantContext)
        // are bound. Testbench skips bootstrap/providers.php, so without this the
        // production listener never fires under PHPUnit and the commit→queue→
        // progress chain is unverifiable (it is exercised by
        // KbUploadCommitIntegrationTest). Safe for every other test: the observer
        // no-ops when a doc carries no kb_upload_batch_item_id metadata.
        $app->register(\App\Providers\KbUploadServiceProvider::class);
        // v4.2/W2 — IngestDocumentFlow definition + FlowRunRecord
        // tenant_id stamping hook. Registered after AppServiceProvider
        // because it depends on the TenantContext singleton it binds.
        $app->register(\App\Providers\FlowServiceProvider::class);
        // Sanctum powers the JSON auth endpoints exercised by
        // tests/Feature/Api/Auth/*. Registered under the same manual
        // pattern as the other project providers above.
        $app->register(\Laravel\Sanctum\SanctumServiceProvider::class);
        // Spatie permissions — registered via the same manual pattern because
        // bootstrap/providers.php uses explicit registration (no package
        // discovery). Without this the Role / Permission models throw
        // "class not registered" under auth:sanctum-protected feature tests.
        $app->register(\Spatie\Permission\PermissionServiceProvider::class);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('ai', require __DIR__.'/../config/ai.php');
        $app['config']->set('kb', require __DIR__.'/../config/kb.php');
        // T1.4 — pluggable ingestion pipeline registry config. Without this,
        // PipelineRegistry boots with an empty converter/chunker list under
        // Testbench (which doesn't auto-load project config files).
        $app['config']->set('kb-pipeline', require __DIR__.'/../config/kb-pipeline.php');
        // Load the project's filesystems config so `config('filesystems.disks.kb.driver')`
        // resolves during tests (Testbench's default skeleton has no `kb` disk).
        // HealthCheckService::kbDiskOk reads this to decide whether to hit
        // the disk or validate the config shape.
        $app['config']->set('filesystems', require __DIR__.'/../config/filesystems.php');
        $app['config']->set('chat-log', require __DIR__.'/../config/chat-log.php');
        $app['config']->set('sanctum', require __DIR__.'/../config/sanctum.php');
        $app['config']->set('cors', require __DIR__.'/../config/cors.php');
        $app['config']->set('auth', require __DIR__.'/../config/auth.php');
        $app['config']->set('permission', require __DIR__.'/../config/permission.php');
        $app['config']->set('rbac', require __DIR__.'/../config/rbac.php');
        // Phase G4 / H2 — admin config carries both the PDF engine knob
        // and the H2 allowed_commands whitelist that CommandRunnerService
        // reads at every preview/run call. Without this set, tests get a
        // null config array and every command looks unknown (404).
        $app['config']->set('admin', require __DIR__.'/../config/admin.php');
        // v4.2/W2 — laravel-flow needs persistence enabled for the
        // kb.ingest end-to-end tests (idempotency lookup, flow_runs +
        // flow_steps + flow_audit row assertions). Override only the
        // persistence.enabled knob; the package SP merges the rest.
        $app['config']->set('laravel-flow', require __DIR__.'/../config/laravel-flow.php');
        // v4.2/W3 — eval-harness CI gate. Without this, EvalRegistrar
        // can't read the golden dataset paths under
        // `eval-harness.askmydocs.golden.*` and the registrar throws.
        $app['config']->set('eval-harness', require __DIR__.'/../config/eval-harness.php');
        // v6.0 / R32 — host override of the AI Act compliance package config.
        // CRITICAL: the package default `routes.middleware` is `['api']` (no
        // auth, no gate), which leaves DSAR / incidents / bias / risk-register
        // / consent endpoints reachable UNAUTHENTICATED. Loading the host
        // config here (the same file production loads) applies the
        // auth:sanctum + viewAiActCompliance gate so AdminAuthorizationMatrixTest
        // verifies the SECURE configuration, not the insecure package default.
        // array_merge keeps the package's other top-level keys (the SP's
        // mergeConfigFrom already populated them at register-time, line ~87)
        // while the host's complete `routes` block wins.
        $app['config']->set('ai-act-compliance', array_merge(
            (array) $app['config']->get('ai-act-compliance', []),
            require __DIR__.'/../config/ai-act-compliance.php',
        ));
        // v8.13/P11 / R32 — host override of the evidence-risk-review package
        // config. The package default `api.middleware` is `[]` (no auth/gate);
        // loading the host config here applies the auth:sanctum +
        // viewEvidenceRiskReview gate so AdminAuthorizationMatrixTest verifies
        // the SECURE configuration, not the open package default. array_merge
        // keeps the package's other top-level keys (mcp, budget, tiers,
        // profiles) while the host's api + review_log + llm blocks win.
        $app['config']->set('evidence-risk-review', array_merge(
            (array) $app['config']->get('evidence-risk-review', []),
            require __DIR__.'/../config/evidence-risk-review.php',
        ));
        // The host config defaults `api.enabled` to the (unset, hence false)
        // `EVIDENCE_RISK_REVIEW_ADMIN_ENABLED` env (R43 default-OFF). Force it ON
        // here so the bulk suite + AdminAuthorizationMatrixTest exercise the
        // SECURED-AND-ENABLED surface (production-with-flag-on parity). The
        // dedicated OFF-path regression (EvidenceRiskReviewAdminFlagTest)
        // overrides this back to false in its own getEnvironmentSetUp to prove
        // the clean 404 degrade.
        $app['config']->set('evidence-risk-review.api.enabled', true);
        // v8.16 / R32 — host override of the laravel-ai-finops package config.
        // CRITICAL: the package default `routes.middleware` is `['api']` +
        // `auth_middleware` is `['auth']` (no Sanctum, no tenant scope, no RBAC),
        // which would leave the spend ledger / budgets / policies / kill-switches
        // reachable with only the stock web auth guard. Loading the host config
        // here (the same file production loads) applies the
        // auth:sanctum + tenant.authorize + finops.authorize stack so
        // AdminAuthorizationMatrixTest verifies the SECURE configuration, not the
        // insecure package default. array_merge keeps the package's other
        // top-level keys (pricing, features, audit, …) while the host's routes /
        // tenancy / currency / master-switch blocks win.
        $app['config']->set('ai-finops', array_merge(
            (array) $app['config']->get('ai-finops', []),
            require __DIR__.'/../config/ai-finops.php',
        ));
        // The host config defaults `enabled` to env('AI_FINOPS_ENABLED', true);
        // force it ON explicitly so the matrix + meter suites exercise the
        // SECURED-AND-ENABLED surface regardless of a stray env in CI.
        $app['config']->set('ai-finops.enabled', true);
        // v8.16 — host override of the finops-admin SPA config. Default
        // enabled=false so the SPA route is NOT registered and every request to
        // /admin/ai-finops is a clean 404 (R43 OFF-state). FinOpsAdminMountingTest
        // flips this ON in its own getEnvironmentSetUp to prove the wired state.
        $app['config']->set('ai-finops-admin', array_merge(
            (array) $app['config']->get('ai-finops-admin', []),
            require __DIR__.'/../config/ai-finops-admin.php',
        ));
        // v8.19 / R32 — host override of the laravel-ai-guardrails package config.
        // CRITICAL: the package default `api.middleware` is `[]` (so an enabled
        // API would fail-close at boot, and the data endpoints carry no auth).
        // Loading the host config here (the same file production loads) turns the
        // API ON behind the auth:sanctum + tenant.authorize + guardrails.authorize
        // stack and flips the append-only stores to `database`, so both
        // AdminAuthorizationMatrixTest and the enforcement suites exercise the
        // SECURE configuration. array_merge keeps the package's other top-level
        // keys (enabled, input_screen, output_handler, modes, …) while the host's
        // api / stores blocks win.
        $app['config']->set('ai-guardrails', array_merge(
            (array) $app['config']->get('ai-guardrails', []),
            require __DIR__.'/../config/ai-guardrails.php',
        ));
        $app['config']->set('ai-guardrails.enabled', true);
        // v8.19/W3 — host override of the guardrails-admin SPA config. Default
        // enabled=false so the GuardrailsAdminEnabled middleware 404s every route
        // under /admin/ai-guardrails (R43 OFF-state). The mounting test flips this
        // ON in its own getEnvironmentSetUp to prove the wired-and-secured route.
        $app['config']->set('ai-guardrails-admin', array_merge(
            (array) $app['config']->get('ai-guardrails-admin', []),
            require __DIR__.'/../config/ai-guardrails-admin.php',
        ));
        // v4.2/W4 sub-PR 5 — pii-redactor-admin published config. Default
        // enabled=false so the SP boot short-circuits before registering
        // routes; tests that exercise the admin routes flip this on
        // explicitly via defineEnvironment() (see PiiRedactorAdminMountingTest).
        $app['config']->set('pii-redactor-admin', require __DIR__.'/../config/pii-redactor-admin.php');
        // v4.2/W4 sub-PR 6 — published flow-admin config. Default
        // enabled=false so every request to a flow-admin route returns
        // 404 via the master-switch middleware. Tests that exercise the
        // wired routes flip this on via getEnvironmentSetUp() in
        // FlowAdminMountingTest.
        $app['config']->set('flow-admin', require __DIR__.'/../config/flow-admin.php');
        // v4.2/W4 sub-PR 7 — published eval-harness-ui config (host-app
        // override of the vendor middleware list). Default enabled=false
        // so every request to the SPA mount returns 404 via the package
        // controller's own check. Tests that exercise the wired routes
        // flip this on via config(['eval-harness-ui.enabled' => true]).
        $app['config']->set('eval-harness-ui', require __DIR__.'/../config/eval-harness-ui.php');
        // padosoft/laravel-invitations host config override. Testbench doesn't
        // auto-load project config/, so the package would otherwise see its
        // vendor default (['web','auth'] route middleware) instead of the host's
        // SPA-session + Sanctum + tenant + manageInvitations stack. Loading the
        // SECURE host config here is what lets AdminAuthorizationMatrixTest verify
        // the real `/api/admin/invitations/*` gate (R32). invitation_required
        // stays its config default (false) — R43 OFF path.
        //
        // Merge (host keys win) instead of replace so any vendor-default key the
        // host config does not restate still survives — mirrors the
        // mergeConfigFrom pattern used for ai-act-compliance / ai-finops /
        // ai-guardrails above (the package SP's hasConfigFile() already merged
        // its default into the container by the time this runs).
        $app['config']->set('invitations', array_merge(
            (array) $app['config']->get('invitations', []),
            require __DIR__.'/../config/invitations.php',
        ));
        // padosoft/laravel-invitations-admin host config. Like the other
        // self-contained admin SPAs, route registration is gated on
        // `enabled` (default false → clean 404, R43). Merge (host keys win) so
        // any vendor-default key the host config doesn't restate survives; the
        // mounting test flips `invitations-admin.enabled` on to prove the
        // wired-and-secured Blade SPA route.
        $app['config']->set('invitations-admin', array_merge(
            (array) $app['config']->get('invitations-admin', []),
            require __DIR__.'/../config/invitations-admin.php',
        ));
        // v8.0/W2.2 — askmydocs.* namespace (notifications subsystem).
        // Without this, `config('askmydocs.notifications.*')` returns
        // null in tests and the preferences-grid endpoint ships an
        // empty `defaults` map. Testbench doesn't auto-load project
        // config/ files; explicit set mirrors the other configs above.
        $app['config']->set('askmydocs', require __DIR__.'/../config/askmydocs.php');
        // Widget KITT config. resource_path() under Testbench points at the
        // skeleton, so override skills_path with the real project resources/
        // so WidgetSkillRegistry resolves askmydocs-assistant@1/manifest.json.
        $app['config']->set('widget', require __DIR__.'/../config/widget.php');
        $app['config']->set('widget.skills_path', realpath(__DIR__.'/../resources/widget/skills') ?: __DIR__.'/../resources/widget/skills');
        // v4.5/W1 — connector framework config. Without this,
        // ConnectorRegistry boots empty and the admin endpoints can't
        // resolve provider knobs.
        $connectorConfig = require __DIR__.'/../config/connectors.php';
        // v4.6 — under Orchestra Testbench, `base_path('composer.lock')`
        // resolves to the testbench vendor skeleton (not the host
        // project), so the package's lockfile-driven auto-discovery
        // sees an empty file. We compensate by seeding the connector
        // FQCNs into the `built_in` slot explicitly — exercises the
        // exact same R23 registration path (FQCN validation,
        // duplicate-key detection, instance caching). Production
        // continues to walk composer.lock just fine.
        $connectorConfig['built_in'] = array_values(array_filter(array_merge(
            $connectorConfig['built_in'] ?? [],
            [
                \Padosoft\AskMyDocsConnectorGoogleDrive\GoogleDriveConnector::class,
                \Padosoft\AskMyDocsConnectorNotion\NotionConnector::class,
                \Padosoft\AskMyDocsConnectorEvernote\EvernoteConnector::class,
                \Padosoft\AskMyDocsConnectorFabric\FabricConnector::class,
                \Padosoft\AskMyDocsConnectorOneDrive\OneDriveConnector::class,
                \Padosoft\AskMyDocsConnectorConfluence\ConfluenceConnector::class,
                \Padosoft\AskMyDocsConnectorJira\JiraConnector::class,
                // v8.17 — first credential-based connector (IMAP).
                \Padosoft\AskMyDocsConnectorImap\ImapConnector::class,
            ],
        ), static fn (string $fqcn): bool => class_exists($fqcn)));
        // v8.17 — in production the IMAP package's ServiceProvider
        // `mergeConfigFrom('config/imap.php', 'connectors.providers.imap')`
        // supplies the xoauth2 provider defaults (authorize_url, scopes, …).
        // Because we overwrite the whole `connectors` config below, replicate
        // that merge here so tests see the same provider config production does.
        $imapConfigPath = __DIR__.'/../vendor/padosoft/askmydocs-connector-imap/config/imap.php';
        if (is_file($imapConfigPath)) {
            // Mirror Laravel's mergeConfigFrom semantics exactly: a SHALLOW
            // array_merge(package, host) where the host's top-level keys win. The
            // host ships no providers.imap block, so this resolves to the package
            // config — same as production.
            $connectorConfig['providers']['imap'] = array_merge(
                (array) require $imapConfigPath,
                (array) ($connectorConfig['providers']['imap'] ?? []),
            );
        }
        $app['config']->set('connectors', $connectorConfig);
        $app['config']->set('laravel-flow.persistence.enabled', true);
        // v4.2/W2 PR #116 — approval gate resume/reject requires a non-Array
        // cache lock store (FlowEngine rejects ArrayStore as process-local).
        // Wire up a per-test-process file cache store + point laravel-flow's
        // queue.lock_store at it. The directory lives under the system temp
        // and is unique per PHPUnit run so parallel processes do not collide.
        $flowLockPath = sys_get_temp_dir().'/askmydocs-flow-locks-'.getmypid();
        // R7 — no @-silenced mkdir; race-tolerant + check return.
        if (! is_dir($flowLockPath) && ! mkdir($flowLockPath, 0o755, true) && ! is_dir($flowLockPath)) {
            throw new \RuntimeException("TestCase: failed to create flow lock directory: {$flowLockPath}");
        }
        $app['config']->set('cache.stores.flow_lock', [
            'driver' => 'file',
            'path' => $flowLockPath,
        ]);
        $app['config']->set('laravel-flow.queue.lock_store', 'flow_lock');
        $app['config']->set('queue.default', 'sync');

        // Make the project's Blade templates (prompts.kb_rag, prompts.promotion_suggest)
        // resolvable from Orchestra Testbench. Without this, any service that
        // renders a view under tests throws "View [...] not found".
        // `realpath()` returns false when the directory is missing (some
        // minimal test environments); fall back to the non-resolved string
        // so we never end up with `view.paths = [false]`.
        $viewPath = __DIR__.'/../resources/views';
        $app['config']->set('view.paths', [realpath($viewPath) ?: $viewPath]);

        // T3.3 — point the translator at the project's lang/ directory so
        // `__('kb.no_grounded_answer')` resolves to the real string under
        // tests (Testbench's default lang_path is its vendor skeleton,
        // which has no `kb` namespace). Same realpath fallback as views.
        $langPath = __DIR__.'/../lang';
        $app->useLangPath(realpath($langPath) ?: $langPath);

        $app['config']->set('auth.providers.users.model', \App\Models\User::class);

        // bootstrap/app.php registers these aliases in production but
        // Orchestra Testbench does not execute that file, so `role:` /
        // `permission:` / `role_or_permission:` middleware declarations
        // would throw `Target class [role] does not exist.` without a
        // manual alias here. Keep the list in sync with bootstrap/app.php.
        $router = $app->make(\Illuminate\Routing\Router::class);
        $router->aliasMiddleware('role', \Spatie\Permission\Middleware\RoleMiddleware::class);
        $router->aliasMiddleware('permission', \Spatie\Permission\Middleware\PermissionMiddleware::class);
        $router->aliasMiddleware('role_or_permission', \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class);
        // v4.1/W4.1.B — chat PII redaction middleware alias. Same reason
        // as the role/permission aliases above: bootstrap/app.php is not
        // executed under Testbench so we mirror the alias here. Keep in
        // sync with the bootstrap/app.php aliases.
        $router->aliasMiddleware('redact-chat-pii', \App\Http\Middleware\RedactChatPii::class);
        $router->aliasMiddleware('auth.sse', \App\Http\Middleware\AuthenticateForSse::class);
        // C1 (R30) — tenant resolution + post-auth header authorization.
        // routes/api.php references `tenant.authorize` on every
        // tenant-DATA route group (chat, kb, admin, compliance) — but NOT
        // on the identity-only `/auth/*` Sanctum group (login, 2FA, password
        // reset), which carries no tenant-scoped data. Without this alias
        // every feature test hitting one of the data groups throws "Target
        // class [tenant.authorize] does not exist". Keep in sync with
        // bootstrap/app.php.
        $router->aliasMiddleware('tenant.resolve', \App\Http\Middleware\ResolveTenant::class);
        $router->aliasMiddleware('tenant.authorize', \App\Http\Middleware\AuthorizeTenantHeader::class);
        // Widget KITT public channel gate. Mirrors bootstrap/app.php
        // (Testbench does not execute that file). The global-prepended
        // HandleWidgetCors is intentionally NOT registered here — CORS is
        // exercised in Playwright E2E, not phpunit; the access gate itself
        // (`widget.key`) is what the feature tests assert.
        $router->aliasMiddleware('widget.key', \App\Http\Middleware\ResolveWidgetKey::class);
        // v6.0 — host-facing AI Act middleware aliases mirroring
        // bootstrap/app.php. The sister package aliases its own
        // `ai-act.*` variants in boot(); we expose them under the
        // `ai.*` shortcut the host routes use.
        $router->aliasMiddleware('ai.disclosure', \Padosoft\AiActCompliance\Disclosure\AiDisclosureMiddleware::class);
        $router->aliasMiddleware('ai.consent', \Padosoft\AiActCompliance\Consent\RequireConsentMiddleware::class);
        // v8.16 — method-aware finops authorization alias. Mirrors
        // bootstrap/app.php (not executed under Testbench). The finops route
        // group's `auth_middleware` references `finops.authorize`; without this
        // alias every finops feature test throws "Target class [finops.authorize]
        // does not exist". Keep in sync with bootstrap/app.php.
        $router->aliasMiddleware('finops.authorize', \App\Http\Middleware\FinOpsAuthorize::class);
        // v8.19 — method-aware guardrails authorization alias. Mirrors
        // bootstrap/app.php (not executed under Testbench). The guardrails API
        // middleware (config('ai-guardrails.api.middleware')) references
        // `guardrails.authorize`; without this alias the guardrails matrix /
        // enforcement tests throw "Target class [guardrails.authorize] does not
        // exist". Keep in sync with bootstrap/app.php.
        $router->aliasMiddleware('guardrails.authorize', \App\Http\Middleware\GuardrailsAuthorize::class);
        // v8.19/W3 — guardrails-admin master-switch gate alias. Mirrors
        // bootstrap/app.php; the package's SPA route stack references
        // `guardrails-admin.enabled` (first), so without this alias the admin
        // mounting test throws "Target class [guardrails-admin.enabled] does not
        // exist". Keep in sync with bootstrap/app.php.
        $router->aliasMiddleware('guardrails-admin.enabled', \App\Http\Middleware\GuardrailsAdminEnabled::class);
        // Desktop PAT least-privilege gate. Mirrors bootstrap/app.php (not
        // executed under Testbench). The /kb/chat + /kb/documents/search +
        // /preview routes reference `token.ability:<ability>`; without this
        // alias TokenTest's enforcement cases throw "Target class
        // [token.ability] does not exist". Keep in sync with bootstrap/app.php.
        $router->aliasMiddleware('token.ability', \App\Http\Middleware\EnforceTokenAbility::class);
    }

    /**
     * Load the project's routes/web.php into Testbench's route stack so
     * route-listing tests (e.g. PiiRedactionMiddlewareScopeTest) can
     * inspect the production middleware bindings without booting a full
     * HTTP request. Mirrors what bootstrap/app.php's `withRouting` does
     * in production.
     */
    protected function defineRoutes($router): void
    {
        require __DIR__.'/../routes/web.php';
        // v8.0/W1.4 — `routes/api.php` is registered with the `api/`
        // prefix in production via withRouting; mirror that here so
        // feature tests can hit `/api/notifications` etc. directly.
        //
        // Copilot iter-2 #10 — apply the `api` middleware group too,
        // so feature tests mirror production's stack (SubstituteBindings
        // for route-model binding + `throttle:api` for rate-limiting).
        // Without this, /api routes under tests behave subtly different
        // from production and tests pass against a stack that production
        // never sees.
        $router->prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../routes/api.php');
    }

    protected function defineDatabaseMigrations(): void
    {
        // v4.6 — `tests/database/migrations/` mirrors the production
        // `database/migrations/` set for the SQLite test runner.
        // Includes the connector framework migrations copied verbatim
        // from `padosoft/askmydocs-connector-base` so the test schema
        // matches what the package SP loads in production.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    /**
     * Pin the request-scoped TenantContext singleton to its default at the
     * start of EVERY test. Testbench refreshes the app per test, but a test
     * that switches tenants (e.g. cross-tenant isolation specs) must never
     * be able to bleed that state into a sibling and cause a downstream
     * tenant-scoped query to return the wrong rows — the kind of silent
     * cross-test contamination that, combined with a mock `->once()`
     * expectation, used to surface as a flaky "active transaction" cascade.
     * Belt-and-suspenders: harmless when the refresh already reset it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->app !== null && $this->app->bound(\App\Support\TenantContext::class)) {
            $this->app->make(\App\Support\TenantContext::class)->reset();
        }
    }

    /**
     * Build a fake OpenAI-SDK `/responses` body for `Http::fake()`.
     *
     * Since v8.16/W2 the no-tools OpenAI chat turn flows through the laravel/ai
     * SDK, which calls the `/responses` endpoint (NOT `/chat/completions`) and
     * parses the `output[].content[].text` shape. Tests that previously faked the
     * `choices[].message.content` chat-completions shape must use this instead.
     *
     * @return array<string, mixed>
     */
    protected static function openAiSdkResponsesBody(
        string $text,
        string $model = 'gpt-4o-mini',
        int $inputTokens = 1,
        int $outputTokens = 1,
    ): array
    {
        return [
            'id' => 'resp_test',
            'model' => $model,
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => $text]],
            ]],
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }
}
