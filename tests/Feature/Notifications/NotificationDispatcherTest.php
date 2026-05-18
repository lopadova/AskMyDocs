<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\ChannelRegistry;
use App\Notifications\Events\KbCanonicalPromoted;
use App\Notifications\Events\KbDecisionDebtThreshold;
use App\Notifications\Events\KbDocumentChanged;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Notifications\RecordingChannel;
use Tests\TestCase;

/**
 * v8.0/W1.2 — NotificationDispatcher behaviour (ADR 0012).
 *
 * Exercises the 3-step protocol with a multi-user fixture (A:
 * in_app+email, B: email-only, C: in_app-only) and asserts:
 *   - one `notification_events` row per recipient with at least
 *     one channel enabled (NO duplicates)
 *   - `channel_dispatch_log` shape matches the per-user preference
 *     mix after the channels have been invoked
 *   - tenant_id is auto-filled from `TenantContext` on every row
 *   - users with zero enabled channels get NO row
 *   - tenant-wide (`user_id == null`) recipients get a row with
 *     the configured system-event channel set
 */
final class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private RecordingChannel $inAppChannel;

    private RecordingChannel $emailChannel;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');

        $registry = app(ChannelRegistry::class);
        $this->inAppChannel = new RecordingChannel(
            NotificationPreference::CHANNEL_IN_APP,
        );
        $this->emailChannel = new RecordingChannel(
            NotificationPreference::CHANNEL_EMAIL,
        );
        $registry->register($this->inAppChannel);
        $registry->register($this->emailChannel);
    }

    public function test_dispatch_creates_one_row_per_recipient_with_at_least_one_enabled_channel(): void
    {
        [$userA, $userB, $userC] = [
            $this->makeUser('a'),
            $this->makeUser('b'),
            $this->makeUser('c'),
        ];
        $this->enablePrefs($userA, [NotificationPreference::CHANNEL_IN_APP, NotificationPreference::CHANNEL_EMAIL]);
        $this->enablePrefs($userB, [NotificationPreference::CHANNEL_EMAIL]);
        $this->enablePrefs($userC, [NotificationPreference::CHANNEL_IN_APP]);

        Event::dispatch(new KbDocumentChanged(
            recipients: [$userA, $userB, $userC],
            payload: ['doc_id' => 'dec-cache-v2', 'change' => 'created'],
            tenantId: 'default',
        ));

        $this->assertSame(3, NotificationEvent::count());
        $this->assertSame(
            [$userA->id, $userB->id, $userC->id],
            NotificationEvent::query()->orderBy('user_id')->pluck('user_id')->all(),
        );

        // Channel order in the dispatch log reflects the
        // `notification_preferences` lookup order, which the dispatcher
        // does NOT pin (DB engine + insert order can permute it).
        // Compare as sets — the assertion that matters is "exactly
        // these channels were dispatched", not "in this specific
        // order".
        $rowA = NotificationEvent::where('user_id', $userA->id)->first();
        $this->assertEqualsCanonicalizing(
            [NotificationPreference::CHANNEL_IN_APP, NotificationPreference::CHANNEL_EMAIL],
            array_column($rowA->channel_dispatch_log, 'channel'),
        );

        $rowB = NotificationEvent::where('user_id', $userB->id)->first();
        $this->assertEqualsCanonicalizing(
            [NotificationPreference::CHANNEL_EMAIL],
            array_column($rowB->channel_dispatch_log, 'channel'),
        );

        $rowC = NotificationEvent::where('user_id', $userC->id)->first();
        $this->assertEqualsCanonicalizing(
            [NotificationPreference::CHANNEL_IN_APP],
            array_column($rowC->channel_dispatch_log, 'channel'),
        );
    }

    public function test_dispatch_skips_recipients_with_no_enabled_channels(): void
    {
        $userA = $this->makeUser('a');
        $userMute = $this->makeUser('mute');
        // userMute has all channels disabled — explicitly create one row
        // disabled to cover the case where DB has rows but all false.
        $this->enablePrefs($userMute, [NotificationPreference::CHANNEL_IN_APP], enabled: false);
        $this->enablePrefs($userA, [NotificationPreference::CHANNEL_IN_APP]);

        Event::dispatch(new KbCanonicalPromoted(
            recipients: [$userA, $userMute],
            payload: ['slug' => 'dec-cache-v2', 'promoted_by' => 'editor-1'],
            tenantId: 'default',
        ));

        $this->assertSame(1, NotificationEvent::count());
        $this->assertSame(
            $userA->id,
            NotificationEvent::first()->user_id,
        );
    }

    public function test_dispatch_tenant_wide_recipient_uses_system_event_channels(): void
    {
        config(['askmydocs.notifications.system_event_channels' => [
            NotificationPreference::CHANNEL_IN_APP,
        ]]);

        Event::dispatch(new KbDecisionDebtThreshold(
            recipients: [null],
            payload: ['threshold_score' => 80, 'doc_count' => 5],
            tenantId: 'default',
        ));

        $row = NotificationEvent::sole();
        $this->assertNull($row->user_id);
        $this->assertSame(
            [NotificationPreference::CHANNEL_IN_APP],
            array_column($row->channel_dispatch_log, 'channel'),
        );
    }

    public function test_dispatch_auto_fills_tenant_id_from_event(): void
    {
        app(TenantContext::class)->set('tenant-context-noise');

        $user = $this->makeUser('cross');
        $this->enablePrefs($user, [NotificationPreference::CHANNEL_IN_APP]);
        // Override the preference's tenant_id to match the event's
        // tenant — BelongsToTenant on the model auto-stamped 'default'
        // from setUp's TenantContext set.
        NotificationPreference::query()
            ->where('user_id', $user->id)
            ->update(['tenant_id' => 'tenant-explicit']);

        Event::dispatch(new KbCanonicalPromoted(
            recipients: [$user],
            payload: ['slug' => 'dec-x'],
            tenantId: 'tenant-explicit',
        ));

        $row = NotificationEvent::sole();
        $this->assertSame('tenant-explicit', $row->tenant_id);
    }

    public function test_dispatch_swallows_channel_exception_and_logs_failed_status(): void
    {
        $user = $this->makeUser('boom');
        $this->enablePrefs($user, ['boom']);

        $registry = app(ChannelRegistry::class);
        $registry->register(new class implements \App\Notifications\Channels\NotificationChannelInterface
        {
            public function name(): string
            {
                return 'boom';
            }

            public function send(
                \App\Notifications\Events\BaseNotificationEvent $event,
                ?User $user,
                NotificationEvent $eventRow,
            ): void {
                throw new \RuntimeException('boom');
            }
        });

        Event::dispatch(new KbCanonicalPromoted(
            recipients: [$user],
            payload: ['slug' => 'dec-x'],
            tenantId: 'default',
        ));

        $row = NotificationEvent::sole();
        $this->assertCount(1, $row->channel_dispatch_log);
        $this->assertSame('failed', $row->channel_dispatch_log[0]['status']);
        $this->assertSame('boom', $row->channel_dispatch_log[0]['error']);
    }

    public function test_dispatch_uses_null_channel_when_no_adapter_registered(): void
    {
        // Reset the registry to its default empty state — only the
        // NullChannel fallback resolves now.
        $this->app->forgetInstance(ChannelRegistry::class);
        app(ChannelRegistry::class);

        $user = $this->makeUser('null-only');
        $this->enablePrefs($user, [NotificationPreference::CHANNEL_IN_APP]);

        Event::dispatch(new KbCanonicalPromoted(
            recipients: [$user],
            payload: ['slug' => 'dec-x'],
            tenantId: 'default',
        ));

        $row = NotificationEvent::sole();
        $this->assertCount(1, $row->channel_dispatch_log);
        $this->assertSame(NotificationPreference::CHANNEL_IN_APP, $row->channel_dispatch_log[0]['channel']);
        $this->assertSame('skipped', $row->channel_dispatch_log[0]['status']);
        $this->assertSame(
            'no adapter registered for channel',
            $row->channel_dispatch_log[0]['error'],
        );
    }

    public function test_dispatch_dedupes_duplicate_recipients(): void
    {
        // Publisher accidentally passes the same user twice (real
        // case: recipient resolution unioning role + per-user
        // overrides). Dispatcher MUST collapse into one row + one
        // channel invocation set.
        $user = $this->makeUser('dup');
        $this->enablePrefs($user, [NotificationPreference::CHANNEL_IN_APP]);

        Event::dispatch(new KbCanonicalPromoted(
            recipients: [$user, $user, $user],
            payload: ['slug' => 'dec-dup'],
            tenantId: 'default',
        ));

        $this->assertSame(1, NotificationEvent::count());
        $this->assertCount(1, $this->inAppChannel->invocations());
    }

    public function test_dispatch_resolves_modified_event_type_for_modified_change(): void
    {
        $user = $this->makeUser('mod');
        // Enable ONLY the `kb_doc_modified` channel — `kb_doc_created`
        // disabled — to prove the dispatcher reads the runtime
        // event_type from the event (not a hard-coded fallback).
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_MODIFIED,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => true,
        ]);

        Event::dispatch(new KbDocumentChanged(
            recipients: [$user],
            payload: ['doc_id' => 'dec-x', 'change' => 'modified'],
            tenantId: 'default',
        ));

        $row = NotificationEvent::sole();
        $this->assertSame(NotificationEvent::EVENT_KB_DOC_MODIFIED, $row->event_type);
        $this->assertCount(1, $this->inAppChannel->invocations());
    }

    public function test_dispatch_still_delivers_to_soft_deleted_recipient(): void
    {
        // Soft delete leaves the row in `users` so the FK on
        // `notification_events.user_id` is satisfied. The dispatcher's
        // exists-check is purely an FK guard, not a "should this user
        // receive notifications?" policy — that decision belongs to
        // the publisher. R2: the guard explicitly opts into
        // `withTrashed()` so soft-deleted users still get their row.
        $user = $this->makeUser('soft');
        $this->enablePrefs($user, [NotificationPreference::CHANNEL_IN_APP]);
        $user->delete();

        Event::dispatch(new KbCanonicalPromoted(
            recipients: [$user],
            payload: ['slug' => 'dec-soft'],
            tenantId: 'default',
        ));

        $this->assertSame(1, NotificationEvent::count());
        $this->assertSame($user->id, NotificationEvent::sole()->user_id);
    }

    public function test_dispatch_skips_recipient_force_deleted_between_publish_and_dispatch(): void
    {
        $stale = $this->makeUser('stale');
        $live = $this->makeUser('live');
        $this->enablePrefs($stale, [NotificationPreference::CHANNEL_IN_APP]);
        $this->enablePrefs($live, [NotificationPreference::CHANNEL_IN_APP]);

        // Simulate the gap: the publisher constructed the event
        // with `$stale` in the recipient list, then before this
        // listener runs the user got force-deleted by an admin
        // action.
        $stale->forceDelete();

        Event::dispatch(new KbCanonicalPromoted(
            recipients: [$stale, $live],
            payload: ['slug' => 'dec-x'],
            tenantId: 'default',
        ));

        // Only the live recipient gets a row. Without the
        // exists-check in the dispatcher the `users` FK on the
        // stale recipient's row would have thrown and aborted the
        // whole batch (including `$live`).
        $this->assertSame(1, NotificationEvent::count());
        $this->assertSame($live->id, NotificationEvent::sole()->user_id);
    }

    public function test_dispatch_preserves_failure_log_when_later_channel_succeeds(): void
    {
        // Regression: previously `appendFailureLog()` saved a freshly
        // reloaded copy of the row while leaving the dispatcher's
        // in-memory `$row` stale, so the next adapter's own
        // `$row->save()` overwrote the failure entry. Pin the
        // fix by running a throwing adapter followed by a
        // succeeding one and asserting both entries persist.
        $user = $this->makeUser('mixed');
        $this->enablePrefs($user, ['boom-first', NotificationPreference::CHANNEL_IN_APP]);

        $registry = app(ChannelRegistry::class);
        $registry->register(new class implements \App\Notifications\Channels\NotificationChannelInterface
        {
            public function name(): string
            {
                return 'boom-first';
            }

            public function send(
                \App\Notifications\Events\BaseNotificationEvent $event,
                ?User $user,
                NotificationEvent $eventRow,
            ): void {
                throw new \RuntimeException('boom-first failed');
            }
        });

        Event::dispatch(new KbCanonicalPromoted(
            recipients: [$user],
            payload: ['slug' => 'dec-mix'],
            tenantId: 'default',
        ));

        $row = NotificationEvent::sole();
        $statuses = array_combine(
            array_column($row->channel_dispatch_log, 'channel'),
            array_column($row->channel_dispatch_log, 'status'),
        );
        $this->assertSame('failed', $statuses['boom-first'] ?? null);
        $this->assertSame('delivered', $statuses[NotificationPreference::CHANNEL_IN_APP] ?? null);
        $this->assertCount(2, $row->channel_dispatch_log);
    }

    private function makeUser(string $slug): User
    {
        return User::create([
            'name' => "dispatcher-user-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    /**
     * @param  array<int, string>  $channels
     */
    private function enablePrefs(User $user, array $channels, bool $enabled = true): void
    {
        foreach ($channels as $channel) {
            NotificationPreference::create([
                'user_id' => $user->id,
                'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
                'channel' => $channel,
                'enabled' => $enabled,
            ]);
            NotificationPreference::create([
                'user_id' => $user->id,
                'event_type' => NotificationEvent::EVENT_KB_CANONICAL_PROMOTED,
                'channel' => $channel,
                'enabled' => $enabled,
            ]);
        }
    }
}
