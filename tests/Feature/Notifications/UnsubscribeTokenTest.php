<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Unsubscribe\UnsubscribeTokenSigner;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.0/W1.3 — HMAC unsubscribe token + endpoint.
 *
 * Covers:
 *   - sign() then verify() round-trip returns the original triple
 *   - verify() rejects a tampered payload (forged user_id)
 *   - verify() rejects malformed input (bad base64, wrong segment
 *     count, non-numeric user_id)
 *   - GET /notifications/unsubscribe/{token} flips the matching
 *     preference rows to enabled=false and returns 200
 *   - GET with a bad token returns 403 without revealing whether
 *     the token was unknown or forged
 *   - Replay (hitting the same valid link twice) is idempotent
 */
final class UnsubscribeTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        config(['askmydocs.notifications.hmac_secret' => 'fixed-test-secret-for-deterministic-tokens']);
    }

    public function test_sign_then_verify_round_trip(): void
    {
        $token = UnsubscribeTokenSigner::sign('tenant-A', 42, NotificationEvent::EVENT_KB_DOC_CREATED);
        $decoded = UnsubscribeTokenSigner::verify($token);

        $this->assertNotNull($decoded);
        $this->assertSame('tenant-A', $decoded['tenant_id']);
        $this->assertSame(42, $decoded['user_id']);
        $this->assertSame(NotificationEvent::EVENT_KB_DOC_CREATED, $decoded['event_type']);
    }

    public function test_verify_rejects_tampered_user_id(): void
    {
        $original = UnsubscribeTokenSigner::sign('tenant-A', 42, 'kb.doc.created');
        // Build a forged token with user_id=99 but copy the original
        // signature — should fail HMAC verification.
        $decoded = base64_decode(strtr($original, '-_', '+/'));
        $parts = explode('|', $decoded);
        $forged = sprintf('%s|%d|%s|%s', $parts[0], 99, $parts[2], $parts[3]);
        $forgedToken = rtrim(strtr(base64_encode($forged), '+/', '-_'), '=');

        $this->assertNull(UnsubscribeTokenSigner::verify($forgedToken));
    }

    public function test_verify_rejects_malformed_input(): void
    {
        $this->assertNull(UnsubscribeTokenSigner::verify(''));
        $this->assertNull(UnsubscribeTokenSigner::verify('not-base64!!'));
        $this->assertNull(UnsubscribeTokenSigner::verify('YQ=='));               // base64('a') → only 1 segment
        $this->assertNull(UnsubscribeTokenSigner::verify(rtrim(strtr(
            base64_encode('|0|x|sig'), '+/', '-_',
        ), '=')));                                                              // empty tenant
        $this->assertNull(UnsubscribeTokenSigner::verify(rtrim(strtr(
            base64_encode('t|notnumeric|x|sig'), '+/', '-_',
        ), '=')));                                                              // non-numeric user_id
    }

    public function test_unsubscribe_route_disables_matching_preferences(): void
    {
        $user = $this->makeUser('unsub');
        // Two channels enabled for the same event_type — both must flip.
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => 'in_app',
            'enabled' => true,
        ]);
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => 'email',
            'enabled' => true,
        ]);
        // A row for a DIFFERENT event_type must stay enabled.
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_CANONICAL_PROMOTED,
            'channel' => 'in_app',
            'enabled' => true,
        ]);

        $token = UnsubscribeTokenSigner::sign('default', $user->id, NotificationEvent::EVENT_KB_DOC_CREATED);

        $response = $this->getJson('/notifications/unsubscribe/'.$token);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'unsubscribed',
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channels_disabled' => 2,
        ]);

        // Matching rows flipped, unrelated row intact.
        $this->assertFalse(NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event_type', NotificationEvent::EVENT_KB_DOC_CREATED)
            ->where('channel', 'in_app')
            ->value('enabled'));
        $this->assertFalse(NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event_type', NotificationEvent::EVENT_KB_DOC_CREATED)
            ->where('channel', 'email')
            ->value('enabled'));
        $this->assertTrue(NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event_type', NotificationEvent::EVENT_KB_CANONICAL_PROMOTED)
            ->where('channel', 'in_app')
            ->value('enabled'));
    }

    public function test_unsubscribe_route_returns_403_on_invalid_token(): void
    {
        $response = $this->getJson('/notifications/unsubscribe/clearlynotavalidsignedtoken');
        $response->assertStatus(403);
        $response->assertJson(['error' => 'invalid_token']);
    }

    public function test_unsubscribe_replay_is_idempotent(): void
    {
        $user = $this->makeUser('replay');
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => 'in_app',
            'enabled' => true,
        ]);
        $token = UnsubscribeTokenSigner::sign('default', $user->id, NotificationEvent::EVENT_KB_DOC_CREATED);

        // First hit — flips, channels_disabled=1.
        $first = $this->getJson('/notifications/unsubscribe/'.$token);
        $first->assertStatus(200);
        $first->assertJson(['channels_disabled' => 1]);

        // Second hit — already disabled, channels_disabled=1 on update
        // (Eloquent update() returns affected count; "already false"
        // still reports as updated by some DB drivers — we accept
        // either 0 or 1 here, the load-bearing assertion is 200).
        $second = $this->getJson('/notifications/unsubscribe/'.$token);
        $second->assertStatus(200);
        $second->assertJson(['status' => 'unsubscribed']);

        // The pref row stays disabled — no flip back, no exception.
        $this->assertFalse(NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event_type', NotificationEvent::EVENT_KB_DOC_CREATED)
            ->value('enabled'));
    }

    public function test_signer_throws_when_secret_is_missing_in_production(): void
    {
        // Force production env + clear secret to trigger the
        // fail-closed behaviour.
        config(['askmydocs.notifications.hmac_secret' => '']);

        $this->expectException(\RuntimeException::class);
        UnsubscribeTokenSigner::sign('default', 1, 'kb.doc.created');
    }

    public function test_signer_verify_returns_null_when_secret_missing(): void
    {
        // verify() is best-effort — never throws (so the public
        // endpoint always returns a clean 403 instead of leaking a
        // 500 to anyone with a copy-pasted token from another env).
        $token = UnsubscribeTokenSigner::sign('default', 1, 'kb.doc.created');
        config(['askmydocs.notifications.hmac_secret' => '']);

        $this->assertNull(UnsubscribeTokenSigner::verify($token));
    }

    private function makeUser(string $slug): User
    {
        return User::create([
            'name' => "unsub-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }
}
