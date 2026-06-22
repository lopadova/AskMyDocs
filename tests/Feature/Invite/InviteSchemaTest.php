<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Models\InviteCampaign;
use App\Models\InviteCode;
use App\Models\Redemption;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 DoD — the canonical invite schema exists with its load-bearing
 * constraints and the tenant auto-fill works.
 *
 * CHECK constraints are pgsql-only (guarded in the migrations), so they are
 * NOT asserted here (the suite runs on SQLite). The UNIQUE constraints ARE
 * created on every driver and are the ones the redemption/referral
 * invariants depend on, so those are exercised.
 */
final class InviteSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_eight_canonical_tables_exist(): void
    {
        foreach ([
            'invite_campaigns',
            'invite_codes',
            'invitations',
            'invite_redemptions',
            'invite_referrals',
            'invite_rewards',
            'invite_waitlist',
            'invite_abuse_signals',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }
    }

    public function test_belongs_to_tenant_autofills_default_tenant(): void
    {
        $admin = $this->makeUser('admin@example.com');

        $campaign = InviteCampaign::create([
            'key' => 'beta-2026',
            'name' => 'Closed Beta',
            'type' => InviteCampaign::TYPE_SINGLE_USE,
            'status' => InviteCampaign::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);

        $this->assertSame('default', $campaign->tenant_id);
    }

    public function test_invite_code_is_unique_per_tenant(): void
    {
        $this->makeCode('ABC12345');

        $this->expectException(QueryException::class);
        $this->makeCode('ABC12345');
    }

    public function test_redemption_unique_code_redeemer_blocks_double_claim(): void
    {
        $code = $this->makeCode('XYZ98765');
        $redeemer = $this->makeUser('redeemer@example.com');

        Redemption::create([
            'code_id' => $code->id,
            'redeemer_id' => $redeemer->id,
            'redeemed_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        Redemption::create([
            'code_id' => $code->id,
            'redeemer_id' => $redeemer->id,
            'redeemed_at' => now(),
        ]);
    }

    public function test_referral_one_referrer_per_referee_per_tenant(): void
    {
        $a = $this->makeUser('a@example.com');
        $b = $this->makeUser('b@example.com');
        $c = $this->makeUser('c@example.com');

        Referral::create([
            'referrer_id' => $a->id,
            'referee_id' => $c->id,
            'status' => Referral::STATUS_PENDING,
            'attributed_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        Referral::create([
            'referrer_id' => $b->id,
            'referee_id' => $c->id,
            'status' => Referral::STATUS_PENDING,
            'attributed_at' => now(),
        ]);
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }

    private function makeCode(string $code): InviteCode
    {
        return InviteCode::create([
            'code' => $code,
            'code_kind' => InviteCode::KIND_RANDOM,
            'state' => InviteCode::STATE_ACTIVE,
            'max_uses' => 1,
            'current_uses' => 0,
        ]);
    }
}
