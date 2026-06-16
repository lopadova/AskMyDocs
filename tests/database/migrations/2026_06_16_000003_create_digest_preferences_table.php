<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.15/W3 — per-user digest preferences.
 *
 * Separate from `notification_preferences` (per-event × per-channel toggles):
 * this row controls the RICH engagement digest — its cadence (`frequency`) and
 * which sections a user wants. One row per (tenant, user); absence = the
 * defaults (weekly, all sections). Tenant-aware per R30/R31.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digest_preferences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->unsignedBigInteger('user_id');
            // weekly | monthly | off
            $table->string('frequency', 16)->default('weekly');
            // null = all sections; otherwise the list of enabled section keys
            // (new_docs, stale_docs, top_gaps, leaderboard, metrics).
            $table->json('sections')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id'], 'uq_digest_prefs_tenant_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_preferences');
    }
};
