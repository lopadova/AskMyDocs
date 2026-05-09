<?php

declare(strict_types=1);

namespace App\Providers;

use App\Flow\Definitions\IngestDocumentFlow;
use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;
use Padosoft\LaravelFlow\FlowEngine;
use Padosoft\LaravelFlow\Models\FlowAuditRecord;
use Padosoft\LaravelFlow\Models\FlowRunRecord;
use Padosoft\LaravelFlow\Models\FlowStepRecord;

/**
 * Registers every AskMyDocs FlowDefinition with the FlowEngine and wires
 * the tenant_id stamping hook on the package's Eloquent records.
 *
 * Why an in-app provider:
 *
 * 1. The vendor `padosoft/laravel-flow` is tenant-agnostic by design
 *    (vendor CLAUDE.md: "Companion dashboard is a separate repo;
 *    package stays headless"). AskMyDocs is multi-tenant per R30/R31 —
 *    every persisted Flow row carries `tenant_id`. The
 *    {@see FlowRunRecord::creating} / {@see FlowStepRecord::creating} /
 *    {@see FlowAuditRecord::creating} hooks below stamp the active
 *    tenant from {@see TenantContext} when the engine inserts a row.
 *
 * 2. Definitions register themselves once per boot. Synchronous,
 *    idempotent (FlowEngine::registerDefinition uses `$name` as key —
 *    re-registering simply overwrites with the same instance).
 */
final class FlowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->stampTenantIdOnFlowRecords();
        $this->registerDefinitions();
    }

    /**
     * Hook the package's Eloquent records' `creating` event to stamp
     * `tenant_id` from the active TenantContext singleton.
     *
     * The package models are `final` and `$guarded = []`, so we cannot
     * extend them — but the Eloquent `creating` hook fires before the
     * INSERT, which is the right place to backfill the column. If the
     * row already carries a `tenant_id` (e.g. a future caller passes
     * one explicitly) we leave it alone — explicit > implicit.
     */
    private function stampTenantIdOnFlowRecords(): void
    {
        $stamp = function ($model): void {
            // Skip if the package has been upgraded to a tenant-aware
            // schema and the engine starts setting it itself, OR if a
            // caller passed it explicitly.
            if (! empty($model->tenant_id)) {
                return;
            }
            /** @var TenantContext $ctx */
            $ctx = app(TenantContext::class);
            $model->tenant_id = $ctx->current();
        };

        FlowRunRecord::creating($stamp);
        FlowStepRecord::creating($stamp);
        FlowAuditRecord::creating($stamp);
    }

    private function registerDefinitions(): void
    {
        /** @var FlowEngine $engine */
        $engine = $this->app->make(FlowEngine::class);

        IngestDocumentFlow::register($engine);
    }
}
