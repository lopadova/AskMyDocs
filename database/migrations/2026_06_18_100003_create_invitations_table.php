<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite system — Invitation (targeted single-recipient invite; wraps a code/token).
 *
 * Canonical entity from docs/04-data-model.md. An Invitation is a *delivery*
 * of a code or a high-entropy link token to one normalized recipient. The
 * `recipient` column is direct PII (email lowercased/trimmed, phone E.164) and
 * is governed by the retention + erasure rules (docs/15-security-privacy.md).
 *
 *   UNIQUE(tenant_id, token)  — NULLs are distinct in pgsql/sqlite, so pure
 *                               code invitations (token NULL) never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();

            $table->unsignedBigInteger('code_id')->nullable(); // wrapped code; null for pure-token invites
            $table->string('token', 128)->nullable();           // high-entropy link token

            $table->string('channel', 10)->default('email'); // email | sms | in_app | link
            $table->string('recipient'); // normalized PII
            $table->unsignedBigInteger('inviter_id'); // Account FK — the referrer candidate

            $table->string('context_ref')->nullable(); // e.g. team/org id
            $table->string('role')->nullable();         // role granted on accept

            $table->string('status', 12)->default('pending'); // pending | accepted | expired | cancelled | bounced
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'token'], 'uq_invitations_tenant_token');
            $table->index(['tenant_id', 'recipient', 'status'], 'ix_invitations_recipient_status');
            $table->index(['tenant_id', 'context_ref', 'status'], 'ix_invitations_context_status');

            $table->foreign('code_id')
                ->references('id')->on('invite_codes')
                ->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('inviter_id')
                ->references('id')->on('users')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE invitations ADD CONSTRAINT chk_invitations_channel "
                . "CHECK (channel IN ('email','sms','in_app','link'))"
            );
            DB::statement(
                "ALTER TABLE invitations ADD CONSTRAINT chk_invitations_status "
                . "CHECK (status IN ('pending','accepted','expired','cancelled','bounced'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
