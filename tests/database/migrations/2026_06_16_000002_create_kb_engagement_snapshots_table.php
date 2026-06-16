<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.15/W1 — daily per-tenant engagement snapshot.
 *
 * Written once per calendar day by {@see \App\Console\Commands\EngagementComputeCommand}
 * (mirrors AdminInsightsSnapshot / KbCanonicalHealthSnapshot). Holds the
 * pre-aggregated engagement metrics the admin dashboard, the user dashboard, and
 * the weekly/monthly digest all read — so no heavy aggregation runs in a request
 * hot path.
 *
 * Partial-failure contract: `metrics` is a single JSON blob; the command computes
 * it transactionally and upserts once. Tenant-aware per R30/R31; composite UNIQUE
 * (tenant_id, snapshot_date) keeps reruns idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_engagement_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->date('snapshot_date');
            // {contributors, new_docs, modified_docs, promoted_docs, reviewed_docs,
            //  answers, refusals, answer_rate, coverage_pct, avg_health,
            //  stale_count, top_contributors:[{user_id,name,score}], trend:{...}}
            $table->json('metrics')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->unsignedInteger('computed_duration_ms')->nullable();

            $table->unique(['tenant_id', 'snapshot_date'], 'uq_kb_engagement_snapshot');
            $table->index(['tenant_id', 'snapshot_date'], 'ix_kb_engagement_snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_engagement_snapshots');
    }
};
