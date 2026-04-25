<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase H2 — confirm_token nonces for destructive commands.
 *
 * Each row is created when POST /api/admin/commands/preview issues a
 * confirm_token for a destructive command. The row is consumed on the
 * matching POST /api/admin/commands/run — mark `used_at`, assert the
 * `args_hash` still matches, reject otherwise.
 *
 * Single-use semantics: once `used_at` is set, a second /run call with
 * the same token is rejected. Expired rows are purged by the
 * `admin-nonces:prune` scheduler (runs daily).
 *
 * Copilot #6 fix: docblock now matches the implementation.
 *
 * token_hash is sha256 of the raw plaintext confirm_token returned by
 * `CommandRunnerService::issueConfirmToken()` (opaque
 * `bin2hex(random_bytes(32))` output). We NEVER store the plaintext
 * token itself; the plaintext round-trips to the client once and is
 * consumed by /run in a transactional lockForUpdate. Lookups use
 * the hash so the DB row itself cannot replay a token even if the
 * admin_command_nonces table leaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_command_nonces', function (Blueprint $table) {
            $table->bigIncrements('id');
            // sha256(token) — never the token itself.
            $table->string('token_hash', 64)->unique('admin_nonces_token_hash_uniq');
            $table->string('command', 120);
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            // sha256 of the canonical-JSON-encoded args at preview time.
            // On /run we recompute and compare — any drift fails the request.
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
