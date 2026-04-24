<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase H2 — admin command runner audit trail.
 *
 * Immutable: every row pins "a user tried to run this command at this
 * moment with these args, and here is how it ended." Rows are written
 * BEFORE Artisan::call() is invoked so a crashing command still produces
 * a forensic record (status flips from `started` → `failed`).
 *
 * Note the deliberate absence of an `updated_at` column — timestamps are
 * `started_at` + `completed_at`, and the row is otherwise append-only
 * after the start → end lifecycle. Bypassing this contract IS a security
 * defect; keep the model on `$guarded = ['id']` to make it hard to drift.
 *
 * `user_id` is nullable with ON DELETE SET NULL — we must not lose audit
 * rows when a user account is hard-deleted. The forensic record outlives
 * the RBAC state.
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
            // 'started' | 'completed' | 'failed' | 'rejected'
            $table->string('status', 20);
            $table->integer('exit_code')->nullable();
            // First 1000 chars of captured output for diagnostics.
            $table->string('stdout_head', 1000)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // No `updated_at` — the row is immutable post-lifecycle-end.

            $table->index(['user_id', 'started_at'], 'admin_audit_user_started_idx');
            $table->index(['command', 'started_at'], 'admin_audit_cmd_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_command_audit');
    }
};
