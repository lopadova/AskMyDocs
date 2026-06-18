<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Models\InviteCode;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Invite\DeferredRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 2 DoD — deferred (guest) redemption: completes after auth, the stash
 * is forgotten BEFORE processing, and a double-fired event does not
 * double-claim.
 */
final class DeferredRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private function deferred(): DeferredRedemptionService
    {
        return app(DeferredRedemptionService::class);
    }

    private function freshSession(): Store
    {
        $session = new Store('invite-test', new \Illuminate\Session\ArraySessionHandler(120));
        $session->start();

        return $session;
    }

    public function test_stash_then_complete_claims_after_auth(): void
    {
        $this->code('GST00001', 3);
        $session = $this->freshSession();
        $user = $this->user('guest@example.com');

        $this->assertTrue($this->deferred()->stash($session, 'gst00001'));

        $result = $this->deferred()->complete($session, $user);
        $this->assertNotNull($result);
        $this->assertTrue($result->ok);
        $this->assertSame(1, Redemption::count());
    }

    public function test_double_fired_event_does_not_double_claim(): void
    {
        $this->code('GST00002', 3);
        $session = $this->freshSession();
        $user = $this->user('guest2@example.com');
        $this->deferred()->stash($session, 'GST00002');

        $first = $this->deferred()->complete($session, $user);
        $second = $this->deferred()->complete($session, $user); // re-fired auth event

        $this->assertNotNull($first);
        $this->assertNull($second, 'Stash was read-and-forgotten; nothing left to process');
        $this->assertSame(1, Redemption::count());
    }

    public function test_invalid_code_is_not_stashed(): void
    {
        $session = $this->freshSession();

        $this->assertFalse($this->deferred()->stash($session, 'DOESNOTEXIST'));
        $this->assertNull($this->deferred()->complete($session, $this->user('nope@example.com')));
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }

    private function code(string $code, int $maxUses): InviteCode
    {
        return InviteCode::create([
            'code' => $code,
            'code_kind' => InviteCode::KIND_RANDOM,
            'state' => InviteCode::STATE_ACTIVE,
            'max_uses' => $maxUses,
            'current_uses' => 0,
        ]);
    }
}
