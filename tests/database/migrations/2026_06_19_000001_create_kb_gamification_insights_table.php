<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.18/W4 — AI gamification insights snapshot (mirrors AdminInsightsSnapshot /
 * KbEngagementSnapshot).
 *
 * Written by {@see \App\Console\Commands\GamificationNarrateCommand} (weekly +
 * on-demand): per-(tenant, scope, period) it persists the deterministic
 * curation-QUALITY metrics blob + the AI-generated narrative blob + the
 * AI-awarded period titles. The "My KB" coaching card, the admin health
 * narrative, and the MCP read tool all read these rows so no LLM call ever runs
 * in a request hot path.
 *
 * `scope_type` is one of user|project|tenant; `scope_id` is the (string) user id
 * for user scope, the project_key for project scope, and '' (empty) for the
 * tenant-wide row — kept a NON-nullable string precisely so the composite UNIQUE
 * (tenant_id, scope_type, scope_id, period_label) treats the tenant row as a
 * concrete key (a NULLable scope_id would let duplicate tenant rows slip past the
 * unique index, since NULLs compare distinct). Idempotent reruns upsert in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_gamification_insights', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            // user | project | tenant
            $table->string('scope_type', 16);
            // user id (string) | project_key | '' for the tenant-wide row.
            $table->string('scope_id', 120)->default('');
            // e.g. "2026-W25" (ISO week) or "2026-06" — the period the insight covers.
            $table->string('period_label', 32);
            // Deterministic curation-quality metrics (GamificationQualityMetricsService).
            $table->json('metrics')->nullable();
            // AI narrative blob: {headline, strengths[], growth[], next_steps[], summary}.
            $table->json('narrative')->nullable();
            // AI-awarded fun period titles: [{key, label, icon, reason}].
            $table->json('titles')->nullable();
            // The model that produced the narrative (null when AI off / failed → deterministic copy).
            $table->string('model', 128)->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->unsignedInteger('computed_duration_ms')->nullable();

            // Composite UNIQUE doubles as the read lookup index; keeps reruns idempotent.
            $table->unique(
                ['tenant_id', 'scope_type', 'scope_id', 'period_label'],
                'uq_kb_gamification_insight',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_gamification_insights');
    }
};
