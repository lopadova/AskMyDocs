<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.16/W3 — server-side per-turn cost authority.
 *
 * Adds an ADDITIVE (nullable) cost surface to `chat_logs`, replacing the old
 * "token cost set arbitrarily" model (static `config/ai.php cost_rates` resolved
 * client-side). The real cost is now resolved SERVER-SIDE at `ChatLogManager`
 * time from the `padosoft/laravel-ai-finops` `CostResolutionService` (the same
 * pricing cascade that feeds the usage ledger) and persisted here:
 *
 *  - `cost`          : decimal(18,8) — the resolved total cost of the turn in the
 *                      base currency. Nullable so legacy rows + turns logged
 *                      while finops is absent/disabled stay untouched (the
 *                      resolver returns null → column stays null).
 *  - `cost_currency` : char(3) — the base currency the `cost` is quoted in
 *                      (`ai-finops.currency.base`, default USD), so a later
 *                      currency-config change never silently re-interprets old rows.
 *  - `trace_id`      : string(64), indexed — correlates this chat-log row to its
 *                      `ai_finops_usage_ledger` row(s) (same `trace_id`), so the
 *                      per-turn chat log and the authoritative ledger entry can be
 *                      joined for reconciliation. Replaces the previous
 *                      synthetic-`invocationId` gap.
 *
 * All three are nullable + non-indexed except `trace_id` (the join key). decimal
 * 18,8 mirrors the ledger's `cost_total` precision so the two never round apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_logs', function (Blueprint $table) {
            $table->decimal('cost', 18, 8)->nullable()->after('total_tokens');
            // char(3) — fixed-width ISO-4217 currency code, matching the docblock.
            $table->char('cost_currency', 3)->nullable()->after('cost');
            $table->string('trace_id', 64)->nullable()->index()->after('cost_currency');
        });
    }

    public function down(): void
    {
        Schema::table('chat_logs', function (Blueprint $table) {
            // Drop the index BEFORE the column (SQLite leaves a dangling index otherwise).
            $table->dropIndex(['trace_id']);
            $table->dropColumn(['cost', 'cost_currency', 'trace_id']);
        });
    }
};
