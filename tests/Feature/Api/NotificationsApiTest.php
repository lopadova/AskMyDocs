<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\NotificationEvent;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.0/W1.4 — REST surface for the notification bell + panel.
 *
 * Covers:
 *   - GET /api/notifications pagination + state filter + event_type
 *   - GET /api/notifications/unread-count
 *   - POST .../{id}/mark-read + dismiss (idempotent, 404 on foreign)
 *   - POST .../mark-all-read returns affected count
 *   - Unauthenticated → 401
 *   - Cross-user / cross-tenant isolation (foreign id → 404, never
 *     reveals "this id exists but belongs to someone else")
 */
final class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    public function test_index_returns_only_unread_by_default(): void
    {
        $user = $this->makeUser('feed');
        $unread = $this->makeEvent($user, 'kb_doc_created');
        $read = $this->makeEvent($user, 'kb_doc_created', ['read_at' => now()]);
        $dismissed = $this->makeEvent($user, 'kb_doc_created', [
            'read_at' => now(),
            'dismissed_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/notifications');

        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$unread->id], $ids, 'default state=unread filters out read + dismissed');
        $this->assertSame('unread', $response->json('meta.state'));
    }

    public function test_index_filters_by_state(): void
    {
        $user = $this->makeUser('state');
        $read = $this->makeEvent($user, 'kb_doc_created', ['read_at' => now()]);
        $this->makeEvent($user, 'kb_doc_created');

        $response = $this->actingAs($user)->getJson('/api/notifications?state=read');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($read->id, $response->json('data.0.id'));
    }

    public function test_index_filters_by_event_type(): void
    {
        $user = $this->makeUser('etype');
        $created = $this->makeEvent($user, 'kb_doc_created');
        $this->makeEvent($user, 'kb_canonical_promoted');

        $response = $this->actingAs($user)->getJson('/api/notifications?state=all&event_type=kb_doc_created');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($created->id, $response->json('data.0.id'));
    }

    public function test_index_paginates(): void
    {
        $user = $this->makeUser('paged');
        foreach (range(1, 25) as $i) {
            $this->makeEvent($user, 'kb_doc_created');
        }

        $response = $this->actingAs($user)->getJson('/api/notifications?per_page=10');

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'));
        $this->assertSame(25, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
    }

    public function test_unread_count(): void
    {
        $user = $this->makeUser('count');
        $this->makeEvent($user, 'kb_doc_created');
        $this->makeEvent($user, 'kb_doc_created');
        $this->makeEvent($user, 'kb_doc_created', ['read_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/notifications/unread-count');

        $response->assertStatus(200);
        $response->assertJson(['unread_count' => 2]);
    }

    public function test_mark_read_flips_read_at_and_is_idempotent(): void
    {
        $user = $this->makeUser('mark');
        $row = $this->makeEvent($user, 'kb_doc_created');

        $first = $this->actingAs($user)->postJson("/api/notifications/{$row->id}/mark-read");
        $first->assertStatus(200);
        $this->assertNotNull($first->json('data.read_at'));

        $second = $this->actingAs($user)->postJson("/api/notifications/{$row->id}/mark-read");
        $second->assertStatus(200);
        // read_at must not change on replay
        $this->assertSame($first->json('data.read_at'), $second->json('data.read_at'));
    }

    public function test_dismiss_flips_both_read_and_dismissed(): void
    {
        $user = $this->makeUser('dismiss');
        $row = $this->makeEvent($user, 'kb_doc_created');

        $response = $this->actingAs($user)->postJson("/api/notifications/{$row->id}/dismiss");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.read_at'));
        $this->assertNotNull($response->json('data.dismissed_at'));
    }

    public function test_mark_all_read_returns_affected_count(): void
    {
        $user = $this->makeUser('all');
        $this->makeEvent($user, 'kb_doc_created');
        $this->makeEvent($user, 'kb_doc_created');
        $this->makeEvent($user, 'kb_doc_created', ['read_at' => now()]); // already read

        $response = $this->actingAs($user)->postJson('/api/notifications/mark-all-read');

        $response->assertStatus(200);
        $response->assertJson(['marked_read' => 2]);
        $this->assertSame(0, NotificationEvent::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count());
    }

    public function test_mark_all_read_respects_event_type_filter(): void
    {
        // Copilot iter-2 #3 — bulk mark-all must NOT flip rows hidden
        // by the active event_type filter. Verify the BE honours the
        // forwarded filter and leaves un-matched rows untouched.
        $user = $this->makeUser('all-filter');
        $created = $this->makeEvent($user, 'kb_doc_created');
        $other = $this->makeEvent($user, 'kb_canonical_promoted');

        $response = $this->actingAs($user)
            ->postJson('/api/notifications/mark-all-read', ['event_type' => 'kb_doc_created']);

        $response->assertStatus(200);
        $response->assertJson(['marked_read' => 1]);
        $this->assertNotNull(NotificationEvent::find($created->id)->read_at);
        $this->assertNull(NotificationEvent::find($other->id)->read_at, 'unrelated event_type must stay unread');
    }

    public function test_index_response_does_not_leak_forensic_dispatch_log_or_tenant_user(): void
    {
        // Copilot iter-3 #1 — the FE feed must not expose
        // `channel_dispatch_log` (per-channel delivery records
        // including email addresses and webhook URLs) nor the
        // `tenant_id` / `user_id` columns (redundant — every row in
        // the response is owned by the calling user by construction).
        $user = $this->makeUser('shape');
        NotificationEvent::create([
            'user_id' => $user->id,
            'event_type' => 'kb_doc_created',
            'payload' => ['title' => 'X'],
            'channel_dispatch_log' => [
                ['channel' => 'email', 'status' => 'delivered', 'at' => now()->toIso8601String(), 'address' => 'private@example.com'],
            ],
        ]);

        $response = $this->actingAs($user)->getJson('/api/notifications');
        $response->assertStatus(200);
        $row = $response->json('data.0');

        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('event_type', $row);
        $this->assertArrayHasKey('payload', $row);
        $this->assertArrayHasKey('created_at', $row);
        $this->assertArrayHasKey('read_at', $row);
        $this->assertArrayHasKey('dismissed_at', $row);
        $this->assertArrayNotHasKey('channel_dispatch_log', $row, 'forensic delivery log must not leak to FE');
        $this->assertArrayNotHasKey('tenant_id', $row, 'tenant_id must not leak to FE');
        $this->assertArrayNotHasKey('user_id', $row, 'user_id must not leak to FE');
    }

    public function test_index_pagination_is_stable_when_created_at_ties(): void
    {
        // Copilot iter-3 #2 — secondary `id DESC` ordering. Seed 5
        // rows with the SAME created_at, then assert the page order
        // is strict id-descending.
        $user = $this->makeUser('tied');
        $sharedAt = now();
        $ids = [];
        foreach (range(1, 5) as $i) {
            $row = NotificationEvent::create([
                'user_id' => $user->id,
                'event_type' => 'kb_doc_created',
                'payload' => ['marker' => $i],
                'channel_dispatch_log' => [],
                'created_at' => $sharedAt,
                'updated_at' => $sharedAt,
            ]);
            $ids[] = $row->id;
        }

        $response = $this->actingAs($user)->getJson('/api/notifications');
        $response->assertStatus(200);
        $returnedIds = array_column($response->json('data'), 'id');
        $expectedDescending = array_reverse($ids);
        $this->assertSame($expectedDescending, $returnedIds, 'tied-timestamp rows must order by id DESC');
    }

    public function test_mark_read_idempotent_replay_returns_stable_timestamp(): void
    {
        // R21 — atomic conditional update. Drives the OBSERVABLE
        // behaviour of the change: a sequential replay must report
        // `changed=false` and the stored `read_at` must not drift.
        // PHPUnit cannot fire two truly-concurrent requests against
        // an in-memory SQLite connection (single-thread, no fork);
        // the underlying SQL pattern (`whereNull('read_at')
        // ->update(['read_at' => now()])`) makes the first writer
        // the only writer at the database layer — the predicate is
        // evaluated atomically in the same UPDATE statement, so a
        // racing second writer would see zero affected rows and a
        // stable timestamp. This test covers the observable
        // contract; the atomic SQL pattern is what guarantees it
        // under true concurrency.
        $user = $this->makeUser('replay');
        $row = $this->makeEvent($user, 'kb_doc_created');

        $first = $this->actingAs($user)->postJson("/api/notifications/{$row->id}/mark-read");
        $first->assertStatus(200);
        $this->assertTrue($first->json('changed'));
        $stamped = $first->json('data.read_at');

        $second = $this->actingAs($user)->postJson("/api/notifications/{$row->id}/mark-read");
        $second->assertStatus(200);
        $this->assertFalse($second->json('changed'), 'replay must report changed=false');
        $this->assertSame($stamped, $second->json('data.read_at'), 'read_at must not drift on replay');
    }

    public function test_dismiss_is_idempotent_on_replay(): void
    {
        // Copilot iter-3 #7 — dismiss is the only mutation with the
        // COALESCE / conditional-update logic; cover replay so the
        // atomic-stamp contract is asserted, not just the happy path.
        $user = $this->makeUser('dismiss-replay');
        $row = $this->makeEvent($user, 'kb_doc_created');

        $first = $this->actingAs($user)->postJson("/api/notifications/{$row->id}/dismiss");
        $first->assertStatus(200);
        $stampedRead = $first->json('data.read_at');
        $stampedDismissed = $first->json('data.dismissed_at');
        $this->assertNotNull($stampedRead);
        $this->assertNotNull($stampedDismissed);

        $second = $this->actingAs($user)->postJson("/api/notifications/{$row->id}/dismiss");
        $second->assertStatus(200);
        $this->assertSame($stampedRead, $second->json('data.read_at'), 'read_at must stay stable');
        $this->assertSame($stampedDismissed, $second->json('data.dismissed_at'), 'dismissed_at must stay stable');
    }

    public function test_dismiss_foreign_user_returns_404_not_403(): void
    {
        // Copilot iter-3 #7 — mirror the cross-user enumeration guard
        // already covered for mark-read.
        $alice = $this->makeUser('alice-dismiss');
        $bob = $this->makeUser('bob-dismiss');
        $bobRow = $this->makeEvent($bob, 'kb_doc_created');

        $response = $this->actingAs($alice)->postJson("/api/notifications/{$bobRow->id}/dismiss");
        $response->assertStatus(404);
        $response->assertJson(['error' => 'not_found']);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/notifications');
        $response->assertStatus(401);
    }

    public function test_foreign_user_id_returns_404_not_403(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');
        $bobRow = $this->makeEvent($bob, 'kb_doc_created');

        // Alice tries to mark-read Bob's notification
        $response = $this->actingAs($alice)->postJson("/api/notifications/{$bobRow->id}/mark-read");

        $response->assertStatus(404);
        $response->assertJson(['error' => 'not_found']);
    }

    public function test_cross_tenant_isolation(): void
    {
        // Same user_id but different tenant context — current user
        // belongs to default tenant, the event is in tenant_other.
        $user = $this->makeUser('xtenant');
        NotificationEvent::create([
            'tenant_id' => 'tenant_other',
            'user_id' => $user->id,
            'event_type' => 'kb_doc_created',
            'payload' => [],
            'channel_dispatch_log' => [],
        ]);

        $response = $this->actingAs($user)->getJson('/api/notifications?state=all');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'), 'rows in tenant_other must not leak into default tenant feed');
    }

    private function makeUser(string $slug): User
    {
        return User::create([
            'name' => "notif-api-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEvent(User $user, string $eventType, array $overrides = []): NotificationEvent
    {
        return NotificationEvent::create(array_merge([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'payload' => ['marker' => uniqid('', true)],
            'channel_dispatch_log' => [],
        ], $overrides));
    }
}
