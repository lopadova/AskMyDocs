<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Models\InviteAnalyticsEvent;
use App\Models\InviteCode;
use App\Models\Invitation;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Invite\ErasureService;
use App\Services\Invite\InvitationService;
use App\Services\Invite\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 6 DoD — the single most important GDPR guarantee: anonymization NEVER
 * corrupts aggregates. After a retention sweep or an erasure, the subject's
 * ip / fingerprint / recipient are gone but current_uses, the redemption row,
 * and the funnel counts are unchanged.
 */
final class PiiRetentionErasureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // PII is only persisted when explicitly enabled (default OFF).
        config(['invite.pii.store_network_fields' => true]);
        Mail::fake();
    }

    public function test_retention_sweep_anonymizes_old_pii_preserving_aggregates(): void
    {
        $code = $this->code('GDPR0001', 3);
        $user = $this->user('subject@example.com');
        app(RedemptionService::class)->redeem('GDPR0001', $user, ['ip' => '203.0.113.9', 'fingerprint' => 'fp-abc']);

        $redemption = Redemption::firstOrFail();
        $this->assertNotNull($redemption->ip, 'ip stored (hashed) when enabled');

        // Age the row past the retention window, then sweep.
        $redemption->update(['redeemed_at' => Carbon::now()->subDays(200)]);
        $summary = app(ErasureService::class)->sweepRetention(90);

        $this->assertSame(1, $summary['redemptions']);
        $redemption->refresh();
        $this->assertNull($redemption->ip, 'ip anonymized');
        $this->assertNull($redemption->fingerprint, 'fingerprint anonymized');

        // Aggregates intact.
        $this->assertSame(1, Redemption::count(), 'row preserved');
        $this->assertSame(1, $code->refresh()->current_uses, 'current_uses unchanged');
        $this->assertSame(1, InviteAnalyticsEvent::where('event_type', InviteAnalyticsEvent::TYPE_CODE_REDEEMED)->count());
    }

    public function test_dry_run_counts_without_writing(): void
    {
        $code = $this->code('DRYRN001', 1);
        $user = $this->user('dry@example.com');
        app(RedemptionService::class)->redeem('DRYRN001', $user, ['ip' => '198.51.100.1']);
        Redemption::query()->update(['redeemed_at' => Carbon::now()->subDays(200)]);

        $summary = app(ErasureService::class)->sweepRetention(90, dryRun: true);

        $this->assertSame(1, $summary['redemptions']);
        $this->assertNotNull(Redemption::firstOrFail()->ip, 'dry-run wrote nothing');
    }

    public function test_erase_account_removes_pii_across_tables_preserving_aggregates(): void
    {
        $code = $this->code('ERASE001', 3);
        $user = $this->user('forgetme@example.com');
        app(RedemptionService::class)->redeem('ERASE001', $user, ['ip' => '203.0.113.20']);
        app(InvitationService::class)->send('forgetme@example.com', $this->user('host@example.com'));

        $summary = app(ErasureService::class)->eraseAccount($user->id, 'forgetme@example.com');

        $this->assertSame(1, $summary['redemptions']);
        $this->assertNull(Redemption::firstOrFail()->ip, 'redemption PII erased');

        $invite = Invitation::where('inviter_id', '!=', $user->id)->first();
        $this->assertSame('erased', $invite->recipient, 'recipient erased');
        $this->assertNull($invite->token, 'bearer token revoked');

        // Aggregates intact.
        $this->assertSame(1, Redemption::count());
        $this->assertSame(1, $code->refresh()->current_uses);
        $this->assertSame(1, InviteAnalyticsEvent::where('event_type', InviteAnalyticsEvent::TYPE_CODE_REDEEMED)->count());
    }

    public function test_atomic_claim_rejects_when_already_full(): void
    {
        // Concurrency invariant (true parallel race is the pgsql CI test): a
        // full code can never be over-claimed — the conditional UPDATE's
        // WHERE current_uses < max_uses is the gate.
        $code = $this->code('FCAP0001', 1);
        app(RedemptionService::class)->redeem('FCAP0001', $this->user('w1@example.com'));

        $result = app(RedemptionService::class)->redeem('FCAP0001', $this->user('w2@example.com'));

        $this->assertFalse($result->ok);
        $this->assertSame(1, $code->refresh()->current_uses, 'never exceeds max_uses');
    }

    private function user(string $email): User
    {
        return User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('secret123')]);
    }

    private function code(string $code, int $maxUses): InviteCode
    {
        return InviteCode::create([
            'code' => $code, 'code_kind' => InviteCode::KIND_RANDOM, 'state' => InviteCode::STATE_ACTIVE,
            'max_uses' => $maxUses, 'current_uses' => 0,
        ]);
    }
}
