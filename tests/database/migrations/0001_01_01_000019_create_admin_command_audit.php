<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of database/migrations/2026_04_24_000010_create_admin_command_audit.php
 * for the SQLite test DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_command_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('command', 120);
            $table->json('args_json');
            $table->string('status', 20);
            $table->integer('exit_code')->nullable();
            $table->string('stdout_head', 1000)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->index(['user_id', 'started_at'], 'admin_audit_user_started_idx');
            $table->index(['command', 'started_at'], 'admin_audit_cmd_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_command_audit');
    }
};
