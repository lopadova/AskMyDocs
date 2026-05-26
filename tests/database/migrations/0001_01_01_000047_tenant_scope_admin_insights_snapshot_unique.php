<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test mirror of
 * database/migrations/2026_05_26_000002_tenant_scope_admin_insights_snapshot_unique.php
 * (R9 — test schema matches production). Runs after the test add_tenant_id
 * mirror (0001_01_01_000024) so the tenant_id column exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_insights_snapshots') || ! Schema::hasColumn('admin_insights_snapshots', 'tenant_id')) {
            return;
        }

        Schema::table('admin_insights_snapshots', function (Blueprint $table): void {
            $table->dropUnique(['snapshot_date']);
            $table->unique(['tenant_id', 'snapshot_date'], 'uq_admin_insights_tenant_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_insights_snapshots') || ! Schema::hasColumn('admin_insights_snapshots', 'tenant_id')) {
            return;
        }

        Schema::table('admin_insights_snapshots', function (Blueprint $table): void {
            $table->dropUnique('uq_admin_insights_tenant_date');
            $table->unique(['snapshot_date']);
        });
    }
};
