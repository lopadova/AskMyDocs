<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds tenant_id to every Flow persistence table.
 *
 * The padosoft/laravel-flow v1.0 package is tenant-agnostic by design
 * (vendor CLAUDE.md: "Companion dashboard is a separate repo; package
 * stays headless"). AskMyDocs is multi-tenant per R30/R31 — every
 * tenant-aware table carries `tenant_id` (default 'default' for v3.x
 * backward compatibility) and tenant-scoped uniqueness constraints.
 *
 * Flow runs orchestrate AskMyDocs's per-tenant pipelines (ingest,
 * canonical promotion, deletion, scheduled prunes). Without tenant_id:
 * - dashboard read models would surface cross-tenant runs
 * - idempotency_key uniqueness could collide across tenants
 * - approval / webhook outbox rows leak tenant context
 *
 * This supplementary migration runs AFTER the package-published Flow
 * migrations so we don't fork upstream code. The composite uniques
 * needed to enforce tenant-scoped idempotency live in app/Flow/* code
 * (sub-PRs 3b/3c/3d) — this migration only adds the column + index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantAwareTables = [
            'flow_runs',
            'flow_steps',
            'flow_audit',
            'flow_approvals',
            'flow_webhook_outbox',
        ];

        foreach ($tenantAwareTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('tenant_id', 50)->default('default')->index();
            });
        }
    }

    public function down(): void
    {
        $tenantAwareTables = [
            'flow_runs',
            'flow_steps',
            'flow_audit',
            'flow_approvals',
            'flow_webhook_outbox',
        ];

        foreach ($tenantAwareTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex([$table->getTable() . '_tenant_id_index']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
