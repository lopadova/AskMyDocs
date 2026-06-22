<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite system — Waitlist (queued signups; skip-the-waitlist via referrals).
 *
 * GREENFIELD — extends beyond the WearFrame reference (docs/04-data-model.md).
 * `email` is direct PII, normalized and unique per tenant. `referral_count`
 * is the skip-the-waitlist counter (each successful referral moves the entry
 * up the queue).
 *
 *   UNIQUE(tenant_id, email)  — no duplicate signups per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_waitlist', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();

            $table->string('email'); // normalized PII
            $table->unsignedInteger('position')->nullable();
            $table->integer('priority')->default(0);
            $table->unsignedInteger('referral_count')->default(0);

            $table->unsignedBigInteger('granted_code_id')->nullable();
            $table->string('status', 12)->default('waiting'); // waiting | invited | converted | removed

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'email'], 'uq_invite_waitlist_tenant_email');
            $table->index(['tenant_id', 'status', 'priority'], 'ix_invite_waitlist_status_priority');
            $table->index('position', 'ix_invite_waitlist_position');

            $table->foreign('granted_code_id')
                ->references('id')->on('invite_codes')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE invite_waitlist ADD CONSTRAINT chk_invite_waitlist_status "
                . "CHECK (status IN ('waiting','invited','converted','removed'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_waitlist');
    }
};
