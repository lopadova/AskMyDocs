<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.0/W2.2 — REST surface backing the React preferences grid.
 *
 * Covers:
 *   - GET /api/notifications/preferences shape
 *   - Empty state (no rows yet) → preferences=[], defaults present
 *   - PUT /api/notifications/preferences upserts the matrix
 *   - PUT is idempotent on replay
 *   - PUT validates event_type + channel against the model enums
 *   - PUT dedups contradictory rows for the same cell (last wins)
 *   - Cross-tenant + cross-user isolation (R30): user A's PUT does NOT
 *     touch user B's rows or another tenant's rows
 *   - 401 unauthenticated on both methods
 */
final class NotificationPreferencesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    public function test_index_returns_grid_scaffolding_when_user_has_no_rows(): void
    {
        $user = $this->makeUser('grid-empty');

        $response = $this->actingAs($user)->getJson('/api/notifications/preferences');

        $response->assertStatus(200);
        $this->assertSame(NotificationEvent::eventTypes(), $response->json('event_types'));
        $this->assertSame(NotificationPreference::availableChannels(), $response->json('channels'));
        // `registered_channels` is the live channel-adapter subset —
        // at least `in_app` always registers in W1.3.
        $this->assertContains('in_app', $response->json('registered_channels'));
        $this->assertIsArray($response->json('defaults'));
        $this->assertTrue($response->json('defaults.in_app'));
        $this->assertSame([], $response->json('preferences'));
    }

    public function test_index_returns_existing_preference_rows(): void
    {
        $user = $this->makeUser('grid-prefilled');
        $this->setPref($user, 'kb_doc_created', 'in_app', true);
        $this->setPref($user, 'kb_doc_created', 'email', false);
        $this->setPref($user, 'kb_canonical_promoted', 'discord', true);

        $response = $this->actingAs($user)->getJson('/api/notifications/preferences');

        $response->assertStatus(200);
        $prefs = $response->json('preferences');
        $this->assertCount(3, $prefs);
        // Order is (event_type ASC, channel ASC) per controller —
        // pin both to catch any future drift.
        $this->assertSame('kb_canonical_promoted', $prefs[0]['event_type']);
        $this->assertSame('discord', $prefs[0]['channel']);
        $this->assertTrue($prefs[0]['enabled']);
        $this->assertSame('kb_doc_created', $prefs[1]['event_type']);
        $this->assertSame('email', $prefs[1]['channel']);
        $this->assertFalse($prefs[1]['enabled']);
        $this->assertSame('kb_doc_created', $prefs[2]['event_type']);
        $this->assertSame('in_app', $prefs[2]['channel']);
        $this->assertTrue($prefs[2]['enabled']);
    }

    public function test_update_upserts_rows_and_returns_updated_grid(): void
    {
        $user = $this->makeUser('upd-fresh');

        $response = $this->actingAs($user)->putJson('/api/notifications/preferences', [
            'preferences' => [
                ['event_type' => 'kb_doc_created', 'channel' => 'in_app', 'enabled' => true],
                ['event_type' => 'kb_doc_created', 'channel' => 'email', 'enabled' => true],
                ['event_type' => 'kb_canonical_promoted', 'channel' => 'discord', 'enabled' => false],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('preferences'));
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'event_type' => 'kb_doc_created',
            'channel' => 'email',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'event_type' => 'kb_canonical_promoted',
            'channel' => 'discord',
            'enabled' => false,
        ]);
    }

    public function test_update_is_idempotent_on_replay(): void
    {
        $user = $this->makeUser('upd-replay');

        $body = [
            'preferences' => [
                ['event_type' => 'kb_doc_modified', 'channel' => 'in_app', 'enabled' => true],
            ],
        ];

        $first = $this->actingAs($user)->putJson('/api/notifications/preferences', $body);
        $first->assertStatus(200);
        $countAfterFirst = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->count();

        $second = $this->actingAs($user)->putJson('/api/notifications/preferences', $body);
        $second->assertStatus(200);
        $countAfterSecond = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'replay must not duplicate');
        $this->assertSame(1, $countAfterSecond);
    }

    public function test_update_dedups_contradictory_rows_for_same_cell_last_wins(): void
    {
        // R25 — the FE should never emit duplicates, but the BE
        // dedup keeps the upsert loop deterministic for CLI / future
        // scripted seeding.
        $user = $this->makeUser('upd-dedup');

        $response = $this->actingAs($user)->putJson('/api/notifications/preferences', [
            'preferences' => [
                ['event_type' => 'kb_doc_created', 'channel' => 'in_app', 'enabled' => true],
                ['event_type' => 'kb_doc_created', 'channel' => 'in_app', 'enabled' => false],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'event_type' => 'kb_doc_created',
            'channel' => 'in_app',
            'enabled' => false,
        ]);
        $this->assertSame(
            1,
            NotificationPreference::query()->where('user_id', $user->id)->count(),
        );
    }

    public function test_update_rejects_unknown_event_type(): void
    {
        $user = $this->makeUser('upd-bad-evt');

        $response = $this->actingAs($user)->putJson('/api/notifications/preferences', [
            'preferences' => [
                ['event_type' => 'kb_unknown_event', 'channel' => 'in_app', 'enabled' => true],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('preferences.0.event_type');
    }

    public function test_update_rejects_unknown_channel(): void
    {
        $user = $this->makeUser('upd-bad-chan');

        $response = $this->actingAs($user)->putJson('/api/notifications/preferences', [
            'preferences' => [
                ['event_type' => 'kb_doc_created', 'channel' => 'pigeon_post', 'enabled' => true],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('preferences.0.channel');
    }

    public function test_update_does_not_touch_other_users_rows(): void
    {
        // R30 / R25 — Alice's PUT must NOT mutate Bob's rows even
        // though both share the same (event_type, channel) tuple.
        $alice = $this->makeUser('iso-alice');
        $bob = $this->makeUser('iso-bob');
        $this->setPref($bob, 'kb_doc_created', 'in_app', true);

        $this->actingAs($alice)->putJson('/api/notifications/preferences', [
            'preferences' => [
                ['event_type' => 'kb_doc_created', 'channel' => 'in_app', 'enabled' => false],
            ],
        ])->assertStatus(200);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $bob->id,
            'event_type' => 'kb_doc_created',
            'channel' => 'in_app',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $alice->id,
            'event_type' => 'kb_doc_created',
            'channel' => 'in_app',
            'enabled' => false,
        ]);
    }

    public function test_update_is_scoped_to_active_tenant(): void
    {
        // R30 — the same user_id could in principle exist twice across
        // tenants; the PUT must scope by (tenant_id, user_id). Seed a
        // row under a foreign tenant; assert it survives an active-tenant
        // PUT that flips the same (event_type, channel).
        $user = $this->makeUser('iso-tenant');

        // Tenant A (foreign) — pre-seeded pref.
        NotificationPreference::query()->create([
            'tenant_id' => 'tenant-foreign',
            'user_id' => $user->id,
            'event_type' => 'kb_doc_created',
            'channel' => 'in_app',
            'enabled' => true,
        ]);

        // Active tenant stays 'default' (set in setUp).
        $this->actingAs($user)->putJson('/api/notifications/preferences', [
            'preferences' => [
                ['event_type' => 'kb_doc_created', 'channel' => 'in_app', 'enabled' => false],
            ],
        ])->assertStatus(200);

        $this->assertDatabaseHas('notification_preferences', [
            'tenant_id' => 'tenant-foreign',
            'user_id' => $user->id,
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('notification_preferences', [
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'enabled' => false,
        ]);
    }

    public function test_index_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/notifications/preferences')->assertStatus(401);
    }

    public function test_update_unauthenticated_returns_401(): void
    {
        $this->putJson('/api/notifications/preferences', ['preferences' => []])
            ->assertStatus(401);
    }

    private function makeUser(string $slug): User
    {
        return User::create([
            'name' => "notif-pref-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    private function setPref(User $user, string $eventType, string $channel, bool $enabled): NotificationPreference
    {
        return NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'channel' => $channel,
            'enabled' => $enabled,
        ]);
    }
}
