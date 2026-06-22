<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen the invite_analytics_events.event_type CHECK to include
 * 'account_provisioned'.
 *
 * The event type was added to App\Models\InviteAnalyticsEvent after the
 * create-table migration had already run on existing databases, so their
 * pgsql CHECK constraint (chk_invite_analytics_event_type) still only allowed
 * the original seven types. Recording an account_provisioned event then failed
 * with a check violation (23514) — and because AnalyticsTracker swallows that
 * error INSIDE the redemption transaction, PostgreSQL left the whole
 * transaction aborted, surfacing downstream as the cryptic
 * "25P02 current transaction is aborted" on the next statement (the referral
 * insert) and breaking invite-gated registration.
 *
 * pgsql-only: SQLite (tests) never had the CHECK. Idempotent — drops the old
 * constraint if present and recreates it with the full type list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE invite_analytics_events DROP CONSTRAINT IF EXISTS chk_invite_analytics_event_type');
        DB::statement(
            'ALTER TABLE invite_analytics_events ADD CONSTRAINT chk_invite_analytics_event_type '
            . "CHECK (event_type IN ('invite_created','invite_sent','invite_opened','code_redeemed','account_provisioned','account_activated','reward_granted','referral_qualified'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE invite_analytics_events DROP CONSTRAINT IF EXISTS chk_invite_analytics_event_type');
        DB::statement(
            'ALTER TABLE invite_analytics_events ADD CONSTRAINT chk_invite_analytics_event_type '
            . "CHECK (event_type IN ('invite_created','invite_sent','invite_opened','code_redeemed','account_activated','reward_granted','referral_qualified'))"
        );
    }
};
