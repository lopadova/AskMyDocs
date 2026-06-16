<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.15/W5 — awarded gamification badges (opt-in via KB_GAMIFICATION_ENABLED).
 *
 * One row per (tenant, user, badge_key) once a contributor crosses the badge's
 * threshold. Tenant-aware per R30/R31; the badge catalog + thresholds live in
 * config (kb.gamification.badges), so this table only records WHAT was earned,
 * not the catalog definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_user_badges', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('badge_key', 64);
            $table->timestamp('awarded_at')->useCurrent();

            $table->unique(['tenant_id', 'user_id', 'badge_key'], 'uq_kb_user_badges');
            $table->index(['tenant_id', 'user_id'], 'ix_kb_user_badges_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_user_badges');
    }
};
