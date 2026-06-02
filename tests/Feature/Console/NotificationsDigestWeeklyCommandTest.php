<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Mail\WeeklyDigestMail;
use App\Models\NotificationDigest;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v8.7/W2 — `notifications:digest-weekly` (closes roadmap R6).
 *
 * Pins: the week's events aggregate into a `notification_digests` row;
 * each email-opted-in user with events gets their own roundup; empty
 * windows produce no digest; re-runs are idempotent.
 */
final class NotificationsDigestWeeklyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    public function test_aggregates_events_and_emails_each_opted_in_user(): void
    {
        Mail::fake();
        $user = $this->makeUser('digest');
        $this->enableEmailPref($user);
        $this->seedEvent($user->id, NotificationEvent::EVENT_KB_DOC_CREATED, ['title' => 'Onboarding guide']);
        $this->seedEvent($user->id, NotificationEvent::EVENT_KB_DOC_CREATED, ['title' => 'Release notes']);
        $this->seedEvent($user->id, NotificationEvent::EVENT_KB_DOC_STALE_REVIEW, ['title' => 'Old runbook']);

        $this->artisan('notifications:digest-weekly')->assertExitCode(0);

        $digest = NotificationDigest::query()->where('tenant_id', 'default')->sole();
        $this->assertNotNull($digest->sent_at);
        $this->assertSame(1, $digest->recipients_count);
        // Aggregate payload groups by event_type with counts.
        $created = collect($digest->payload)->firstWhere('event_type', NotificationEvent::EVENT_KB_DOC_CREATED);
        $this->assertSame(2, $created['count'] ?? null);

        Mail::assertQueued(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_user_without_email_preference_is_not_emailed(): void
    {
        Mail::fake();
        $user = $this->makeUser('no-email');
        // in_app pref only — not email.
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => NotificationPreference::CHANNEL_IN_APP,
            'enabled' => true,
        ]);
        $this->seedEvent($user->id, NotificationEvent::EVENT_KB_DOC_CREATED, ['title' => 'Doc']);

        $this->artisan('notifications:digest-weekly')->assertExitCode(0);

        Mail::assertNothingQueued();
        // The tenant aggregate row still records the activity.
        $this->assertSame(0, NotificationDigest::query()->sole()->recipients_count);
    }

    public function test_no_events_in_window_produces_no_digest(): void
    {
        Mail::fake();
        $user = $this->makeUser('stale-events');
        $this->enableEmailPref($user);
        // Event OUTSIDE the 7-day window.
        $this->seedEvent($user->id, NotificationEvent::EVENT_KB_DOC_CREATED, ['title' => 'Old'], daysAgo: 10);

        $this->artisan('notifications:digest-weekly')->assertExitCode(0);

        $this->assertSame(0, NotificationDigest::count());
        Mail::assertNothingQueued();
    }

    public function test_rerun_is_idempotent_on_the_week_row(): void
    {
        Mail::fake();
        $user = $this->makeUser('idem');
        $this->enableEmailPref($user);
        $this->seedEvent($user->id, NotificationEvent::EVENT_KB_DOC_CREATED, ['title' => 'Doc']);

        $this->artisan('notifications:digest-weekly')->assertExitCode(0);
        $this->artisan('notifications:digest-weekly')->assertExitCode(0);

        // Still exactly one (tenant, week_start_date) row.
        $this->assertSame(1, NotificationDigest::query()->where('tenant_id', 'default')->count());
        // And the email is sent exactly ONCE — the second run must not
        // re-queue duplicate digests (sent_at is the send-once latch).
        Mail::assertQueued(WeeklyDigestMail::class, 1);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function seedEvent(int $userId, string $eventType, array $payload, int $daysAgo = 1): void
    {
        $event = NotificationEvent::create([
            'user_id' => $userId,
            'event_type' => $eventType,
            'payload' => $payload,
            'channel_dispatch_log' => [],
        ]);
        // created_at is not fillable — set it explicitly for the window.
        $event->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    }

    private function enableEmailPref(User $user): void
    {
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'channel' => NotificationPreference::CHANNEL_EMAIL,
            'enabled' => true,
        ]);
    }

    private function makeUser(string $slug): User
    {
        return User::create([
            'name' => "digest-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }
}
