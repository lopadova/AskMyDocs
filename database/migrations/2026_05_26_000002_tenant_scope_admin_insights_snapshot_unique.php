<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.0.3 security hotfix (Audit#3 CRITICAL-3 / R30) — admin_insights_snapshots
 * was UNIQUE on `snapshot_date` alone, which allowed only ONE snapshot per
 * calendar day across the whole instance. InsightsComputeCommand now writes
 * ONE snapshot PER TENANT per day, so the uniqueness must be
 * (tenant_id, snapshot_date). No FK references this unique, so the portable
 * Blueprint API is safe here.
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
