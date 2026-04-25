<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of database/migrations/2026_04_24_000011_create_admin_command_nonces.php
 * for the SQLite test DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_command_nonces', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('token_hash', 64)->unique('admin_nonces_token_hash_uniq');
            $table->string('command', 120);
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('args_hash', 64);
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            $table->index('expires_at', 'admin_nonces_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_command_nonces');
    }
};
