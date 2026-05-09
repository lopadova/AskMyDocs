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
];
