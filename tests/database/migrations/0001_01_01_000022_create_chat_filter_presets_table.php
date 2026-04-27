<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-environment mirror of `2026_04_27_000001_create_chat_filter_presets_table`
 * — Testbench runs migrations from `tests/database/migrations/` so the
 * SQLite in-memory database carries the same schema as production
 * (PostgreSQL with pgvector). Keep the column shapes 1:1 with the
 * production migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_filter_presets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('name', 120);
            // JSON column type — Laravel translates to TEXT under SQLite.
            $table->json('filters');
            $table->timestamps();

            $table->unique(['user_id', 'name'], 'uq_chat_filter_presets_user_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_filter_presets');
    }
};
