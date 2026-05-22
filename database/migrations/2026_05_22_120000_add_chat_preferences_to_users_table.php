<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.0.1 / deep-review F5 — server-side chat preferences per user.
 *
 * Replaces the previous browser-local localStorage toggle for the
 * counterfactual panel (which only persisted per browser, not per
 * user — multi-device / fresh session / cache wipe all lost the
 * setting). The chat preferences live on the `users` row because the
 * preference is user-level identity (not tenant-scoped) — the same
 * user crossing tenant boundaries keeps their chat ergonomics.
 *
 * Shape (FE owns the schema; BE just persists):
 *   { "counterfactual_enabled": true }
 *
 * Future chat-level toggles (sound, density, etc.) land in the same
 * column without a new migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // JSON is supported on PostgreSQL ≥ 9.4 and SQLite ≥ 3.9
            // (the test driver). The column is nullable so existing
            // rows skip a backfill — a null value is read as "no
            // preferences set, use defaults" by the controller.
            $table->json('chat_preferences')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('chat_preferences');
        });
    }
};
