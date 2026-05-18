<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\NotificationDigest;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.0/W1.1 — Notification system schema + model lifecycle (ADR 0012).
 *
 * Covers:
 *   - BelongsToTenant auto-fill from TenantContext (R31)
 *   - Event lifecycle (create → mark read → dismiss)
 *   - FK cascade when User is hard-deleted
 *   - Composite uniques (preferences + digests) start with tenant_id (R30)
 *   - JSON casts roundtrip
 */
final class NotificationModelsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    public function test_event_auto_fills_tenant_id_from_context(): void
    {
        app(TenantContext::class)->set('tenant-acme');

        $event = NotificationEvent::create([
            'user_id' => $this->makeUser()->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'payload' => ['doc_id' => 'dec-cache-v2'],
        ]);

        $this->assertSame('tenant-acme', $event->fresh()->tenant_id);
    }

    public function test_event_lifecycle_create_mark_read_dismiss(): void
    {
        $user = $this->makeUser();

        $event = NotificationEvent::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_MODIFIED,
            'payload' => ['doc_id' => 'dec-cache-v2', 'title' => 'Cache v2'],
        ]);

        $this->assertNull($event->read_at);
        $this->assertNull($event->dismissed_at);

        $event->update(['read_at' => Carbon::now()]);
        $this->assertNotNull($event->fresh()->read_at);
        $this->assertNull($event->fresh()->dismissed_at);

        $event->update(['dismissed_at' => Carbon::now()]);
        $this->assertNotNull($event->fresh()->dismissed_at);
    }

    public function test_event_payload_and_dispatch_log_roundtrip_as_arrays(): void
    {
        $event = NotificationEvent::create([
            'user_id' => $this->makeUser()->id,
            'event_type' => NotificationEvent::EVENT_KB_CANONICAL_PROMOTED,
            'payload' => ['slug' => 'dec-cache-v2', 'promoted_by' => 'editor-1'],
            'channel_dispatch_log' => [
                ['channel' => 'in_app', 'status' => 'delivered', 'at' => '2026-05-18T10:00:00Z'],
                ['channel' => 'email', 'status' => 'queued', 'at' => '2026-05-18T10:00:01Z'],
            ],
        ]);

        $reloaded = $event->fresh();
        $this->assertSame('dec-cache-v2', $reloaded->payload['slug']);
        $this->assertCount(2, $reloaded->channel_dispatch_log);
        $this->assertSame('delivered', $reloaded->channel_dispatch_log[0]['status']);
    }

    public function test_event_user_id_can_be_null_for_tenant_wide_events(): void
    {
        // Use a non-digest event type — the weekly digest aggregate
        // lives in `notification_digests`, NOT in this table, so
        // there is no `EVENT_WEEKLY_DIGEST` constant on the model.
        // The tenant-wide-event branch is exercised here with a
        // generic decision-debt threshold event the dispatcher fires
        // once at the org level (e.g. for the tenant DPO inbox).
        $event = NotificationEvent::create([
            'user_id' => null,
            'event_type' => NotificationEvent::EVENT_KB_DECISION_DEBT_THRESHOLD,
            'payload' => ['threshold_score' => 80, 'doc_count' => 5],
        ]);

        $this->assertNull($event->user_id);
        $this->assertSame(
            NotificationEvent::EVENT_KB_DECISION_DEBT_THRESHOLD,
            $event->event_type,
        );
    }

    public function test_event_cascades_when_user_force_deleted(): void
    {
        $user = $this->makeUser();
        $event = NotificationEvent::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'payload' => ['doc_id' => 'dec-cache-v2'],
        ]);

        $eventId = $event->id;
        $user->forceDelete();

        $this->assertNull(NotificationEvent::find($eventId));
    }

    public function test_preference_cascades_when_user_force_deleted(): void
    {
        // Mirror of the events cascade: the preferences table has its
        // own FK `user_id → users(id) ON DELETE CASCADE`, and if the
        // cascade were silently regressed at the schema level (e.g.
        // CASCADE → SET NULL), the dispatcher would later try to
        // deliver to a deleted user. Lock the contract here so the
        // schema cannot regress without this test going red.
        $user = $this->makeUser();
        $pref = NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => NotificationPreference::CHANNEL_EMAIL,
            'enabled' => true,
        ]);

        $prefId = $pref->id;
        $user->forceDelete();

        $this->assertNull(NotificationPreference::find($prefId));
    }

    public function test_preference_composite_unique_blocks_duplicate_tenant_user_event_channel(): void
    {
        $user = $this->makeUser();

        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => NotificationPreference::CHANNEL_EMAIL,
            'enabled' => true,
        ]);

        $this->expectException(QueryException::class);
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => NotificationPreference::CHANNEL_EMAIL,
            'enabled' => false,
        ]);
    }

    public function test_preference_same_event_channel_can_coexist_across_tenants(): void
    {
        $user = $this->makeUser();

        app(TenantContext::class)->set('tenant-a');
        $a = NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => NotificationPreference::CHANNEL_EMAIL,
            'enabled' => true,
        ]);

        app(TenantContext::class)->set('tenant-b');
        $b = NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => NotificationPreference::CHANNEL_EMAIL,
            'enabled' => false,
        ]);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame('tenant-a', $a->tenant_id);
        $this->assertSame('tenant-b', $b->tenant_id);
    }

    public function test_preference_enabled_cast_is_bool(): void
    {
        $pref = NotificationPreference::create([
            'user_id' => $this->makeUser()->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => 0,
        ]);

        $this->assertIsBool($pref->fresh()->enabled);
        $this->assertFalse($pref->fresh()->enabled);
    }

    public function test_digest_composite_unique_blocks_duplicate_tenant_week(): void
    {
        NotificationDigest::create([
            'week_start_date' => '2026-05-18',
            'payload' => ['created' => [], 'modified' => []],
            'recipients_count' => 0,
        ]);

        $this->expectException(QueryException::class);
        NotificationDigest::create([
            'week_start_date' => '2026-05-18',
            'payload' => ['created' => ['doc-1'], 'modified' => []],
            'recipients_count' => 0,
        ]);
    }

    public function test_digest_same_week_can_coexist_across_tenants(): void
    {
        app(TenantContext::class)->set('tenant-a');
        $a = NotificationDigest::create([
            'week_start_date' => '2026-05-18',
            'payload' => ['created' => ['doc-a-1']],
            'recipients_count' => 0,
        ]);

        app(TenantContext::class)->set('tenant-b');
        $b = NotificationDigest::create([
            'week_start_date' => '2026-05-18',
            'payload' => ['created' => ['doc-b-1']],
            'recipients_count' => 0,
        ]);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame('tenant-a', $a->tenant_id);
        $this->assertSame('tenant-b', $b->tenant_id);
    }

    public function test_digest_payload_roundtrips_and_sent_at_caster(): void
    {
        $digest = NotificationDigest::create([
            'week_start_date' => '2026-05-18',
            'payload' => [
                'created' => ['doc-a', 'doc-b'],
                'modified' => ['doc-c'],
                'promoted' => [],
                'deleted' => [],
            ],
            'sent_at' => Carbon::parse('2026-05-19T09:00:00Z'),
            'recipients_count' => 17,
        ]);

        $reloaded = $digest->fresh();
        $this->assertSame(['doc-a', 'doc-b'], $reloaded->payload['created']);
        $this->assertSame(17, $reloaded->recipients_count);
        $this->assertNotNull($reloaded->sent_at);
        $this->assertSame('2026-05-19', $reloaded->sent_at->format('Y-m-d'));
    }

    private function makeUser(): User
    {
        // `User` is intentionally NOT tenant-aware: it represents a
        // cross-tenant identity. The v3 multi-tenancy migration
        // (`database/migrations/2026_04_28_000001_add_tenant_id_to_v3_tables.php`)
        // EXCLUDES `users` / `roles` / `permissions` from the
        // tenant_id column rollout, and `User::$fillable` omits it
        // accordingly. The notification tests bind tenancy via the
        // active `TenantContext`, never per-User.
        return User::create([
            'name' => 'notif-test-actor',
            'email' => 'notif-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }
}
