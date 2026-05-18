<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\Channels;

use App\Mail\NotificationMail;
use App\Models\KnowledgeDocument;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\ChannelRegistry;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\InAppChannel;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v8.0/W1.3 — InAppChannel + EmailChannel real adapters.
 *
 * Pins the W1.3 acceptance gate from plan §C.1:
 *   `Mail::fake()->assertQueued(NotificationMail::class) +
 *    notification_events row visible`
 *
 * The provider boots with both adapters registered (in_app + email),
 * so dispatch flows the usual way:
 *   1. KnowledgeDocument::created hook fires KbDocumentChanged
 *   2. NotificationDispatcher creates the notification_events row
 *   3. For each enabled channel pref, the dispatcher invokes the
 *      matching adapter on $row in deterministic order:
 *        - InAppChannel appends status: 'delivered'
 *        - EmailChannel queues NotificationMail + appends status: 'queued'
 *   4. Final $row->channel_dispatch_log has BOTH entries.
 */
final class InAppEmailChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        // EmailChannel signs unsubscribe URLs via UnsubscribeTokenSigner
        // which fails-closed on missing config — pin a fixed test secret
        // so the channel doesn't record "failed (no HMAC secret)" and
        // skip the Mail::queue() call.
        config(['askmydocs.notifications.hmac_secret' => 'fixed-test-secret-for-deterministic-tokens']);
        Mail::fake();
    }

    public function test_dispatch_queues_notification_mail_and_writes_in_app_log_entry(): void
    {
        $subscriber = $this->makeMember('w13-sub', 'proj-w13');
        $this->enablePref($subscriber, NotificationEvent::EVENT_KB_DOC_CREATED, 'in_app');
        $this->enablePref($subscriber, NotificationEvent::EVENT_KB_DOC_CREATED, 'email');

        KnowledgeDocument::create($this->docAttributes('proj-w13', 'docs/w13.md', 'w13-1'));

        // Acceptance gate #1: NotificationMail queued exactly once
        // to the subscriber's email.
        Mail::assertQueued(NotificationMail::class, function (NotificationMail $mail) use ($subscriber): bool {
            return $mail->hasTo($subscriber->email)
                && $mail->eventType === NotificationEvent::EVENT_KB_DOC_CREATED
                && $mail->tenantId === 'default'
                && str_contains($mail->unsubscribeUrl, '/notifications/unsubscribe/');
        });

        // Acceptance gate #2: notification_events row exists with
        // BOTH channel entries.
        $row = NotificationEvent::sole();
        $statuses = array_combine(
            array_column($row->channel_dispatch_log, 'channel'),
            array_column($row->channel_dispatch_log, 'status'),
        );
        $this->assertSame('delivered', $statuses['in_app'] ?? null, 'in_app should record delivered');
        $this->assertSame('queued', $statuses['email'] ?? null, 'email should record queued');
    }

    public function test_email_channel_skips_recipient_with_null_email(): void
    {
        $subscriber = $this->makeMember('w13-noemail', 'proj-w13-noemail');
        // Force-null the email AFTER creation (the schema requires
        // a value at insert time).
        User::query()->where('id', $subscriber->id)->update(['email' => '']);
        $subscriber->refresh();

        $this->enablePref($subscriber, NotificationEvent::EVENT_KB_DOC_CREATED, 'email');

        KnowledgeDocument::create($this->docAttributes('proj-w13-noemail', 'docs/x.md', 'x-1'));

        Mail::assertNothingQueued();

        $row = NotificationEvent::sole();
        $statuses = array_combine(
            array_column($row->channel_dispatch_log, 'channel'),
            array_column($row->channel_dispatch_log, 'status'),
        );
        $this->assertSame('skipped', $statuses['email'] ?? null);
    }

    public function test_in_app_channel_appends_only_log_entry_no_extra_row(): void
    {
        // Direct InAppChannel invocation — the dispatcher already
        // created the row, the channel should NOT create another.
        $row = NotificationEvent::create([
            'event_type' => 'kb.doc.created',
            'payload' => ['change' => 'created'],
            'channel_dispatch_log' => [],
        ]);

        $before = NotificationEvent::count();
        (new InAppChannel())->send(
            event: new \App\Notifications\Events\KbDocumentChanged(
                recipients: [],
                payload: ['change' => 'created'],
                tenantId: 'default',
            ),
            user: null,
            eventRow: $row,
        );

        $this->assertSame($before, NotificationEvent::count(), 'InAppChannel must NOT create a second row');
        $row->refresh();
        $this->assertCount(1, $row->channel_dispatch_log);
        $this->assertSame('in_app', $row->channel_dispatch_log[0]['channel']);
        $this->assertSame('delivered', $row->channel_dispatch_log[0]['status']);
    }

    public function test_email_subject_matches_event_type_constant(): void
    {
        // Pins the constant→subject mapping so a future drift between
        // NotificationEvent::EVENT_* (snake_case) and the match arms
        // in NotificationMail::renderSubject() (caught in PR #189
        // round-11 — the templates were using dot-notation strings
        // that silently fell through to the generic default) fails
        // here loudly.
        $cases = [
            NotificationEvent::EVENT_KB_DOC_CREATED => 'New document published in your knowledge base',
            NotificationEvent::EVENT_KB_DOC_MODIFIED => 'A document you follow was updated',
            NotificationEvent::EVENT_KB_CANONICAL_PROMOTED => 'A decision was promoted to canonical',
            NotificationEvent::EVENT_KB_DECISION_DEBT_THRESHOLD => 'Decision debt threshold reached',
            NotificationEvent::EVENT_COLLECTION_NEW_MEMBER => 'A new document joined a collection you follow',
        ];
        foreach ($cases as $eventType => $expectedSubject) {
            $mail = new \App\Mail\NotificationMail(
                tenantId: 'default',
                eventType: $eventType,
                payload: [],
                eventRowId: 1,
                unsubscribeUrl: 'https://example.com/u/abc',
                userName: null,
            );
            $this->assertSame(
                $expectedSubject,
                $mail->envelope()->subject,
                "Subject for {$eventType} should be '{$expectedSubject}', got '{$mail->envelope()->subject}'",
            );
        }
    }

    public function test_email_channel_mailable_carries_unsubscribe_url(): void
    {
        $user = $this->makeMember('w13-unsub', 'proj-w13-unsub');
        $this->enablePref($user, NotificationEvent::EVENT_KB_DOC_CREATED, 'email');

        KnowledgeDocument::create($this->docAttributes('proj-w13-unsub', 'docs/u.md', 'u-1'));

        Mail::assertQueued(NotificationMail::class, function (NotificationMail $mail) use ($user): bool {
            // The unsubscribe URL embeds an HMAC-signed token —
            // bytes are opaque, but URL shape is stable.
            $matches = [];
            if (! preg_match('#/notifications/unsubscribe/([A-Za-z0-9_-]+)$#', $mail->unsubscribeUrl, $matches)) {
                return false;
            }
            $verified = \App\Notifications\Unsubscribe\UnsubscribeTokenSigner::verify($matches[1]);
            return $verified !== null
                && $verified['user_id'] === $user->id
                && $verified['tenant_id'] === 'default'
                && $verified['event_type'] === NotificationEvent::EVENT_KB_DOC_CREATED;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function docAttributes(string $projectKey, string $sourcePath, string $hashSeed, string $title = 'Doc'): array
    {
        return [
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'source_type' => 'markdown',
            'title' => $title,
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $hashSeed),
            'version_hash' => hash('sha256', $hashSeed),
            'metadata' => [],
            'indexed_at' => now(),
        ];
    }

    private function enablePref(User $user, string $eventType, string $channel): void
    {
        NotificationPreference::create([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'channel' => $channel,
            'enabled' => true,
        ]);
    }

    private function makeUser(string $slug): User
    {
        return User::create([
            'name' => "channel-user-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }

    private function makeMember(string $slug, string $projectKey): User
    {
        $user = $this->makeUser($slug);
        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => $projectKey,
            'role' => 'member',
            'scope_allowlist' => null,
        ]);
        return $user;
    }
}
