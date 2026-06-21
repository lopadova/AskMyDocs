<?php

$providers = [
    App\Providers\AppServiceProvider::class,
    App\Providers\AiServiceProvider::class,
    App\Providers\ChatLogServiceProvider::class,
    // v8.9 — UI drag-and-drop upload progress wiring: KnowledgeDocument
    // observer (doc-id link) + 3 queue-event listeners (item status
    // lifecycle). Only acts on IngestDocumentJob runs carrying an upload
    // batch-item id in metadata; a no-op for every other ingest path.
    App\Providers\KbUploadServiceProvider::class,
    // v4.1/W4.1.B — PII redactor package SP. Listed explicitly because
    // package auto-discovery via `bootstrap/cache/packages.php` is
    // brittle on the Windows + Herd dev environment (artisan
    // `package:discover` intermittently flags the cache dir as
    // unwritable even when it isn't). Listing it here is a no-op
    // when auto-discovery succeeds and a safety net when it doesn't.
    Padosoft\PiiRedactor\PiiRedactorServiceProvider::class,
    // v4.3/W1 sub-PR 4.5 — PII redactor comprehensive boundary coverage.
    // Wires 5 Eloquent observers + 1 Queue listener + 1 Monolog processor
    // + 1 Flow CurrentPayloadRedactorProvider binding in ONE place. Every
    // touch-point is INDEPENDENTLY default-off; gates live in
    // `config/kb.php` `pii_redactor` block under the `redact_*` keys.
    // Listed AFTER PiiRedactorServiceProvider so the package's
    // RedactorEngine binding is in the container before observers run,
    // and AFTER any package providers that bind the Flow contract default
    // so our `redact_flow_payloads`-gated singleton wins when active.
    App\Providers\PiiBoundaryCoverageServiceProvider::class,
    // v4.2/W2 — laravel-flow saga engine SP. Listed explicitly for the
    // same reason as PiiRedactor above (auto-discovery is brittle on
    // Windows + Herd). Required for `Flow::define()` / `Flow::execute()`
    // on the kb.ingest definition + future canonical / scheduled flows.
    Padosoft\LaravelFlow\LaravelFlowServiceProvider::class,
    // v4.2/W2 — registers IngestDocumentFlow definition with FlowEngine
    // on every boot (synchronous, in-process). Must run AFTER the
    // package SP above so the FlowEngine singleton is available, and
    // also wires the FlowRunRecord::creating() hook that stamps
    // tenant_id from the active TenantContext (R30/R31).
    App\Providers\FlowServiceProvider::class,
    // v4.2/W4 sub-PR 5 — PII Redactor Admin SPA. Listed explicitly for
    // the same reason as PiiRedactor / LaravelFlow above (auto-discovery
    // is brittle on Windows + Herd). Required so config + migrations
    // can be published via `vendor:publish --tag=pii-redactor-admin-*`
    // and so the admin routes are registered when
    // `PII_REDACTOR_ADMIN_ENABLED=true`. Disabled-by-default: when the
    // env flag is false the SP is loaded but its boot() short-circuits
    // before registering any routes.
    Padosoft\PiiRedactorAdmin\PiiRedactorAdminServiceProvider::class,
    // v4.2/W4 sub-PR 6 — Flow Admin SPA (padosoft/laravel-flow-admin
    // v1.0.0). Listed explicitly for the same auto-discovery brittleness
    // rationale as the siblings above. The package is unconditionally
    // route-registering — the host-app-level `enabled` flag is enforced
    // by AskMyDocs through:
    //   1. config/flow-admin.php receives an additional `enabled` key
    //      (not present in the vendor's published config).
    //   2. FlowAdminIntegrationServiceProvider defines
    //      `Gate::define('viewFlowAdmin')` which the configured outer
    //      middleware `can:viewFlowAdmin` consults.
    //   3. The custom `App\Http\Middleware\FlowAdminEnabled` middleware
    //      (aliased by FlowAdminIntegrationServiceProvider)
    //      `abort(404)`s when the env switch is off, satisfying R14
    //      (correct semantic for a disabled subsystem) AND
    //      preventing routes from leaking on an unprepared deploy.
    Padosoft\LaravelFlowAdmin\FlowAdminServiceProvider::class,
    App\Providers\FlowAdminIntegrationServiceProvider::class,
    // v8.19/W3 — AI Guardrails Admin SPA (padosoft/laravel-ai-guardrails-admin
    // v1.0.0). Listed explicitly for the same auto-discovery brittleness
    // rationale as the siblings above. Like flow-admin, the package mounts its
    // catch-all SPA route UNCONDITIONALLY — the host-app `enabled` flag is
    // enforced through config/ai-guardrails-admin.php (the `enabled` key, not in
    // the vendor config) + the `guardrails-admin.enabled` middleware (first in
    // the route stack, aliased in bootstrap/app.php) which `abort(404)`s when the
    // env switch is off, satisfying R43 (clean OFF-state) and preventing the
    // route from leaking on an unprepared deploy.
    Padosoft\LaravelAiGuardrailsAdmin\LaravelAiGuardrailsAdminServiceProvider::class,
    // v8.0/W1.2 — Notification dispatch pipeline. Binds the
    // ChannelRegistry singleton and registers NotificationDispatcher
    // as the listener for the 4 BaseNotificationEvent subclasses
    // shipped in W1.2 (KbDocumentChanged + KbCanonicalPromoted +
    // KbDecisionDebtThreshold + CollectionNewMember). Real channel
    // adapters (InApp + Email) register themselves in W1.3; this
    // provider ships only the registry + NullChannel fallback so
    // the dispatch path is end-to-end testable in W1.2.
    App\Providers\NotificationServiceProvider::class,
    // M4 — KITT Widget services. Registers WidgetAiToolRegistry as a
    // singleton with built-in AiTool FQCNs (R23: FQCN validation +
    // supports() mutex on registration). Must boot AFTER
    // AppServiceProvider so ChatRetrievalService is bound for
    // SearchKnowledgeBaseTool.
    App\Providers\WidgetServiceProvider::class,
    // v4.6 — Connector framework SP (padosoft/askmydocs-connector-base
    // v1.1.1). Listed explicitly for the same auto-discovery
    // brittleness rationale as the siblings above. Required to
    // register:
    //   - ConnectorRegistry singleton (composer-extra auto-discovery)
    //   - OAuthCredentialVault singleton
    //   - Package's TenantContext (we alias the host's onto it in
    //     AppServiceProvider::register())
    //   - NullConnectorIngestionContract fallback (host re-binds the
    //     real implementation right after via the singleton in
    //     AppServiceProvider).
    //   - 2 framework migrations (connector_installations +
    //     connector_credentials).
    Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider::class,
    // v4.6 — 7 standalone connector SPs. Each one registers the
    // connector's own provider (env-driven config, route helpers if
    // any, OAuth callback wiring) and surfaces the connector FQCN via
    // `extra.askmydocs.connectors` so the framework registry picks it
    // up. Listed explicitly so a `bootstrap/cache/packages.php`
    // staleness or `--no-dev` install can't silently drop a
    // connector from the registry — same defence-in-depth posture as
    // the Pii / Flow / EvalHarness siblings above.
    Padosoft\AskMyDocsConnectorGoogleDrive\GoogleDriveServiceProvider::class,
    Padosoft\AskMyDocsConnectorNotion\NotionServiceProvider::class,
    Padosoft\AskMyDocsConnectorEvernote\EvernoteServiceProvider::class,
    Padosoft\AskMyDocsConnectorFabric\FabricServiceProvider::class,
    Padosoft\AskMyDocsConnectorOneDrive\OneDriveServiceProvider::class,
    Padosoft\AskMyDocsConnectorConfluence\ConfluenceServiceProvider::class,
    Padosoft\AskMyDocsConnectorJira\JiraServiceProvider::class,
    // v8.13/P11 — Evidence Risk Review core package (padosoft/laravel-
    // evidence-risk-review v1.1). Listed explicitly for the same
    // auto-discovery brittleness rationale as the siblings above. Registers
    // the review engine + the HTTP API, mounted at the secure host prefix
    // via config/evidence-risk-review.php (auth:sanctum + tenant.authorize +
    // can:viewEvidenceRiskReview). The companion `-admin` package is installed
    // but `dont-discover`ed in composer.json — AskMyDocs renders the admin
    // natively (cross-mount against this core's HTTP API), the established
    // pattern for every sister admin surface. AppServiceProvider binds the
    // package TenantResolver -> host TenantContext (R30) and the
    // EvidenceReviewerLlmContract -> AiManager adapter (default-OFF, R43).
    Padosoft\EvidenceRiskReview\EvidenceRiskReviewServiceProvider::class,
    // v4.2/W4 sub-PR 7 — Eval Harness UI SPA (padosoft/eval-harness-ui
    // v1.0.0). Listed explicitly because the package lives in
    // require-dev and Laravel's auto-discovery cache may exclude
    // require-dev packages on optimised production builds. This
    // explicit entry keeps the SP loaded in dev / test / staging where
    // the dashboard is wanted.
    //
    // Two AskMyDocs fences gate the package routes regardless of how
    // the SP is loaded:
    //   1. The package controller's own `eval-harness-ui.enabled`
    //      check (default false) returns 404 for every request.
    //   2. The `App\Http\Middleware\EvalHarnessUiNonProduction`
    //      middleware (registered as alias `eval-harness-ui.non-prod`
    //      by EvalHarnessUiIntegrationServiceProvider) returns 404
    //      whenever `APP_ENV=production` — even if an operator flipped
    //      `EVAL_HARNESS_UI_ENABLED=true` by accident in prod.
    //
    // The integration SP also wires the `eval-harness-ui.tenant-header`
    // middleware alias (R30 — injects X-Eval-Harness-Tenant from
    // TenantContext) and is intentionally separate from the vendor SP
    // so the touchpoint is grep-able in one place.
    // (Eval Harness UI providers conditionally appended below — see
    // class_exists() guard for the require-dev resilience reason.)
];

// v4.2/W4 sub-PR 7 — Eval Harness UI providers are appended ONLY when
// the vendor class is loadable. The package lives in `require-dev`,
// so production deploys with `composer install --no-dev` will not have
// the autoload entry. Without this guard, Laravel would crash on boot
// in production with "Class not found" before the FE / Gate / env
// checks ever fire. The guard also means the integration SP never
// loads in production (its bind targets are vendor-class type-hinted
// in dev only). Both fences (env flag + APP_ENV) still apply when the
// vendor class IS loaded — see the comment block above the appended
// FQCNs for the full defence-in-depth chain.
if (class_exists(Padosoft\EvalHarnessUi\EvalHarnessUiServiceProvider::class)) {
    $providers[] = Padosoft\EvalHarnessUi\EvalHarnessUiServiceProvider::class;
    $providers[] = App\Providers\EvalHarnessUiIntegrationServiceProvider::class;
}

return $providers;
