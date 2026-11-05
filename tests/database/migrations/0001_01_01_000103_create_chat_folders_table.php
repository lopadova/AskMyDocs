<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-environment mirror of `2026_10_03_000001_create_chat_folders_table`
 * — Testbench runs migrations from `tests/database/migrations/` so the
 * SQLite database carries the same schema as production (PostgreSQL).
 * Keep the column shapes 1:1 with the production migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_folders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'name'], 'uq_chat_folders_tenant_user_name');
            $table->index(['tenant_id', 'user_id', 'position'], 'chat_folders_tenant_user_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_folders');
    }
};
