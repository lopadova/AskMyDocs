<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite system — append-only funnel event log (docs/11-analytics.md).
 *
 * One row per canonical funnel transition. `event_id` is a deterministic,
 * caller-supplied idempotency anchor (e.g. "redeemed:{redemption_id}") so a
 * replayed domain event / job retry collapses to a single row —
 * UNIQUE(tenant_id, event_id) is the guard. `actor_hash` is a non-reversible
 * HMAC of the account ref (pseudonymous); `context` carries opaque refs only,
 * never raw PII.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_analytics_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();

            $table->string('event_id', 191);
            $table->string('event_type', 32); // invite_created | invite_sent | invite_opened | code_redeemed | account_provisioned | account_activated | reward_granted | referral_qualified
            $table->string('actor_hash', 128)->nullable(); // pseudonymous HMAC

            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('code_id')->nullable();
            $table->unsignedBigInteger('referral_id')->nullable();

            $table->json('context')->nullable();
            $table->timestamp('occurred_at');

            $table->unique(['tenant_id', 'event_id'], 'uq_invite_analytics_tenant_event');
            $table->index(['tenant_id', 'event_type', 'occurred_at'], 'ix_invite_analytics_type_time');
            $table->index(['tenant_id', 'campaign_id', 'event_type'], 'ix_invite_analytics_campaign_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE invite_analytics_events ADD CONSTRAINT chk_invite_analytics_event_type "
                . "CHECK (event_type IN ('invite_created','invite_sent','invite_opened','code_redeemed','account_provisioned','account_activated','reward_granted','referral_qualified'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_analytics_events');
    }
};
