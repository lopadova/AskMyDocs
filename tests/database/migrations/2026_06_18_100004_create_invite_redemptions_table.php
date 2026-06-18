<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invite system — Redemption (immutable claim event; append-only).
 *
 * Canonical entity from docs/04-data-model.md. This table carries the
 * idempotency anchor of the whole system:
 *
 *   UNIQUE(code_id, redeemer_id)  — one claim per account per code.
 *
 * The atomic claim (docs/07-redemption-flow.md) is a conditional
 * `UPDATE invite_codes ... WHERE current_uses < max_uses` followed by an
 * INSERT here; a UNIQUE violation on (code_id, redeemer_id) is caught and
 * returned as idempotent success, never as an error.
 *
 * `ip` / `user_agent` / `fingerprint` are PII / PII-adjacent: stored hashed
 * where present, nullable, short-retention, and erasable
 * (docs/15-security-privacy.md). `redeemed_at` and the row itself are NEVER
 * mutated — anonymization overwrites the PII columns in place so aggregate
 * counts (current_uses, K-factor) survive erasure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();

            $table->unsignedBigInteger('code_id');
            $table->unsignedBigInteger('redeemer_id'); // Account FK
            $table->unsignedBigInteger('invitation_id')->nullable();

            $table->timestamp('redeemed_at');

            // PII — null unless abuse review needs it; hashed when present.
            $table->string('ip', 128)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('fingerprint', 128)->nullable();

            $table->json('context')->nullable(); // structured, no raw PII

            // Idempotency anchor — one claim per (code, account).
            $table->unique(['code_id', 'redeemer_id'], 'uq_invite_redemptions_code_redeemer');
            $table->index('code_id', 'ix_invite_redemptions_code');
            $table->index('redeemer_id', 'ix_invite_redemptions_redeemer');
            $table->index('redeemed_at', 'ix_invite_redemptions_redeemed_at');

            $table->foreign('code_id')
                ->references('id')->on('invite_codes')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('redeemer_id')
                ->references('id')->on('users')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('invitation_id')
                ->references('id')->on('invitations')
                ->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_redemptions');
    }
};
