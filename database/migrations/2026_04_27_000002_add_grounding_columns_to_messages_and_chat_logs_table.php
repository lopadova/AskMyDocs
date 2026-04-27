<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.0/T3.1 — Anti-hallucination tier-1 columns.
 *
 * Adds two nullable columns to BOTH `messages` (per-message grounding signal)
 * and `chat_logs` (analytics + dashboard rollups):
 *  - `confidence`  : tinyint 0..100 — composite score (vec sim + threshold
 *                    margin + chunk diversity + citation density). Computed
 *                    by `ConfidenceCalculator` (T3.2). Nullable so legacy
 *                    rows pre-T3 stay untouched.
 *  - `refusal_reason` : short string tag (`no_relevant_context`,
 *                       `llm_self_refusal`, future tags). Nullable on the
 *                       happy path; present when the controller short-circuits
 *                       (T3.3) or the LLM emits the `__NO_GROUNDED_ANSWER__`
 *                       sentinel (T3.4). 64 chars is generous — current set
 *                       of tags fits in 24, the buffer covers future taxonomy.
 *
 * Both columns are nullable + non-indexed: confidence is a write-rare /
 * read-often column accessed by row id (already indexed), and refusal_reason
 * has only a handful of distinct values — adding an index would not help
 * the query planner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedTinyInteger('confidence')->nullable()->after('rating');
            $table->string('refusal_reason', 64)->nullable()->after('confidence');
        });

        Schema::table('chat_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('confidence')->nullable()->after('latency_ms');
            $table->string('refusal_reason', 64)->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['confidence', 'refusal_reason']);
        });

        Schema::table('chat_logs', function (Blueprint $table) {
            $table->dropColumn(['confidence', 'refusal_reason']);
        });
    }
};
