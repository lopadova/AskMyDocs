<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds tenant_id to every Flow persistence table AND replaces the
 * package-published global UNIQUE on flow_runs.idempotency_key with a
 * tenant-scoped composite UNIQUE (tenant_id, idempotency_key).
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
 * migrations so we don't fork upstream code. The package's
 * idempotency_key UNIQUE is GLOBAL by design (single-tenant default);
 * we replace it here with the tenant-scoped composite required for
 * AskMyDocs's multi-tenant correctness.
 */
return new class extends Migration
{
    private const TENANT_AWARE_TABLES = [
        'flow_runs',
        'flow_steps',
        'flow_audit',
        'flow_approvals',
        'flow_webhook_outbox',
    ];

    public function up(): void
    {
        foreach (self::TENANT_AWARE_TABLES as $tableName) {
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

        // Replace the package's global UNIQUE on flow_runs.idempotency_key
        // with a tenant-scoped composite. Two tenants choosing the same
        // idempotency key (e.g. "default:docs/intro.md:abc123") would
        // otherwise collide at the DB level the moment a second tenant
        // exists.
        if (Schema::hasTable('flow_runs') && Schema::hasColumn('flow_runs', 'tenant_id')) {
            Schema::table('flow_runs', function (Blueprint $table): void {
                $table->dropUnique(['idempotency_key']);
                $table->unique(
                    ['tenant_id', 'idempotency_key'],
                    'flow_runs_tenant_idempotency_unique',
                );
            });
        }
    }

    public function down(): void
    {
        // Restore the package-canonical global UNIQUE before dropping
        // tenant_id so the column drop doesn't leave a dangling
        // composite index.
        if (Schema::hasTable('flow_runs') && Schema::hasColumn('flow_runs', 'tenant_id')) {
            Schema::table('flow_runs', function (Blueprint $table): void {
                $table->dropUnique('flow_runs_tenant_idempotency_unique');
                $table->unique('idempotency_key');
            });
        }

        foreach (self::TENANT_AWARE_TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
