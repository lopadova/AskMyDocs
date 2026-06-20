<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-runner mirror of database/migrations/2026_06_18_000001_add_cost_to_chat_logs_table.php
 * (v8.16/W3). Adds the additive cost surface (`cost`, `cost_currency`, `trace_id`)
 * to `chat_logs` for the SQLite test schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_logs', function (Blueprint $table) {
            $table->decimal('cost', 18, 8)->nullable()->after('total_tokens');
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
