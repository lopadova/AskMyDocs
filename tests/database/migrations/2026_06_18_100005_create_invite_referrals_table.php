<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite system — Referral (directed edge referrer→referee).
 *
 * GREENFIELD — extends beyond the WearFrame reference (docs/08-referral-graph.md).
 * Realized from a Redemption that is attributable to an inviter. Two storage
 * invariants make attribution abuse-resistant:
 *
 *   UNIQUE(tenant_id, referee_id)        — one referrer per referee (first-wins)
 *   CHECK(referrer_id <> referee_id)     — no self-referral (pgsql backstop)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_referrals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();

            $table->unsignedBigInteger('referrer_id'); // Account FK
            $table->unsignedBigInteger('referee_id');   // Account FK

            $table->unsignedBigInteger('code_id')->nullable();
            $table->unsignedBigInteger('redemption_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();

            $table->string('status', 12)->default('pending'); // pending | qualified | rewarded | reversed
            $table->unsignedInteger('depth')->default(1);

            $table->timestamp('attributed_at');
            $table->timestamp('qualified_at')->nullable();
            $table->timestamps();

            // One referrer per referee, scoped per tenant (R30).
            $table->unique(['tenant_id', 'referee_id'], 'uq_invite_referrals_tenant_referee');
            $table->index(['referrer_id', 'status'], 'ix_invite_referrals_referrer_status');
            $table->index(['campaign_id', 'status'], 'ix_invite_referrals_campaign_status');

            $table->foreign('referrer_id')
                ->references('id')->on('users')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('referee_id')
                ->references('id')->on('users')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('code_id')
                ->references('id')->on('invite_codes')
                ->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('redemption_id')
                ->references('id')->on('invite_redemptions')
                ->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('campaign_id')
                ->references('id')->on('invite_campaigns')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE invite_referrals ADD CONSTRAINT chk_invite_referrals_no_self '
                . 'CHECK (referrer_id <> referee_id)'
            );
            DB::statement(
                "ALTER TABLE invite_referrals ADD CONSTRAINT chk_invite_referrals_status "
                . "CHECK (status IN ('pending','qualified','rewarded','reversed'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_referrals');
    }
};
