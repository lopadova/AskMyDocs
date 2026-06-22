<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite system — Reward (accrued incentive).
 *
 * GREENFIELD — extends beyond the WearFrame reference (docs/09-rewards-engine.md).
 * Double-sided: both the `referrer` and the `referee` party can earn a reward.
 * The double-grant guard is the database, not application bookkeeping:
 *
 *   UNIQUE(idempotency_key)  — re-delivering ReferralQualified / replaying a
 *                              grant never produces a second row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_rewards', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();

            $table->unsignedBigInteger('referral_id')->nullable();
            $table->unsignedBigInteger('redemption_id')->nullable();
            $table->unsignedBigInteger('beneficiary_id'); // Account FK

            $table->string('party', 10);  // referrer | referee
            $table->string('type', 16);   // credit | perk | tier_upgrade | discount
            $table->integer('amount')->nullable();
            $table->string('unit')->nullable();
            $table->string('trigger', 16); // on_redemption | on_activation | on_milestone
            $table->string('state', 10)->default('pending'); // pending | granted | reversed | expired

            // Double-grant guard — globally unique (already encodes tenant+subject in the key).
            $table->string('idempotency_key', 191);

            $table->timestamp('granted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique('idempotency_key', 'uq_invite_rewards_idempotency_key');
            $table->index(['beneficiary_id', 'state'], 'ix_invite_rewards_beneficiary_state');
            $table->index(['state', 'trigger'], 'ix_invite_rewards_state_trigger');

            $table->foreign('referral_id')
                ->references('id')->on('invite_referrals')
                ->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('redemption_id')
                ->references('id')->on('invite_redemptions')
                ->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('beneficiary_id')
                ->references('id')->on('users')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE invite_rewards ADD CONSTRAINT chk_invite_rewards_party "
                . "CHECK (party IN ('referrer','referee'))"
            );
            DB::statement(
                "ALTER TABLE invite_rewards ADD CONSTRAINT chk_invite_rewards_type "
                . "CHECK (type IN ('credit','perk','tier_upgrade','discount'))"
            );
            DB::statement(
                "ALTER TABLE invite_rewards ADD CONSTRAINT chk_invite_rewards_trigger "
                . "CHECK (trigger IN ('on_redemption','on_activation','on_milestone'))"
            );
            DB::statement(
                "ALTER TABLE invite_rewards ADD CONSTRAINT chk_invite_rewards_state "
                . "CHECK (state IN ('pending','granted','reversed','expired'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_rewards');
    }
};
