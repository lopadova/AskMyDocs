<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AiServiceProvider::class,
    App\Providers\ChatLogServiceProvider::class,
    // v4.1/W4.1.B — PII redactor package SP. Listed explicitly because
    // package auto-discovery via `bootstrap/cache/packages.php` is
    // brittle on the Windows + Herd dev environment (artisan
    // `package:discover` intermittently flags the cache dir as
    // unwritable even when it isn't). Listing it here is a no-op
    // when auto-discovery succeeds and a safety net when it doesn't.
    Padosoft\PiiRedactor\PiiRedactorServiceProvider::class,
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
];
