<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase I — AI insights snapshot table.
 *
 * ONE row per day (uniqueness anchor: `snapshot_date`). The scheduler
 * recomputes at 05:00 via `insights:compute`; the SPA then reads the
 * pre-computed row — LLM calls NEVER happen on a user request, so the
 * /app/admin/insights view loads in constant time regardless of corpus
 * size.
 *
 * Every insight column is independently nullable so a partial-failure
 * write strategy works: if suggestTags() throws a RuntimeException
 * (LLM timeout / quota), the command writes null for that column and
 * keeps the other five. The snapshot is never partially-absent from
 * the table; it's either missing entirely or present with possibly-null
 * cells.
 *
 * Not tenant-scoped — insights aggregate across the whole installation.
 * The coverage-gaps + promotion suggestions lists carry enough context
 * (project_key inside the JSON payload) that the SPA can drill down
 * per-project without a parallel table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_insights_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            // UNIQUE by calendar day so `insights:compute --force`
            // replaces today's row deterministically. Schedule fires at
            // 05:00; --date=today is the default.
            $table->date('snapshot_date')->unique();
            // Six insight payloads — each a JSON blob independently
            // nullable. The compute command's partial-failure strategy
            // writes null to the column that threw and proceeds.
            $table->json('suggest_promotions')->nullable();
            $table->json('orphan_docs')->nullable();
            $table->json('suggested_tags')->nullable();
            $table->json('coverage_gaps')->nullable();
            $table->json('stale_docs')->nullable();
            $table->json('quality_report')->nullable();
            // Compute telemetry — useful for diagnostics and
            // scheduler-widget rendering ("yesterday's snapshot took
            // 42s, six of seven functions completed").
            $table->timestamp('computed_at')->nullable();
            $table->integer('computed_duration_ms')->nullable();

            // Index mirrors the read path — the SPA fetches the latest
            // row by ORDER BY snapshot_date DESC LIMIT 1.
            $table->index(['snapshot_date'], 'admin_insights_snapshots_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_insights_snapshots');
    }
};
