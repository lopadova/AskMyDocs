<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum personal access tokens.
 *
 * Vendored in (verbatim from laravel/sanctum) because Sanctum 4.x only
 * *publishes* this migration — it does NOT auto-load it. The SPA auth flow
 * uses stateful cookie sessions, which never needed the table, so it was
 * never published. The Bearer-token endpoint (POST /api/auth/token) used by
 * the Tauri desktop client calls $user->createToken(), which requires it.
 *
 * Standard portable schema (no vector column), so the test mirror under
 * tests/database/migrations/ is byte-identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
