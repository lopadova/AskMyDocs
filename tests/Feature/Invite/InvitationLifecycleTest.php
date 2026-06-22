<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Mail\InvitationMail;
use App\Models\AbuseSignal;
use App\Models\Invitation;
use App\Models\InviteAnalyticsEvent;
use App\Models\User;
use App\Services\Invite\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 5 (notifications) DoD — invitation send/accept lifecycle, queued +
 * idempotent email, bounce→status, pending-count, expiry sweep.
 */
final class InvitationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function service(): InvitationService
    {
        return app(InvitationService::class);
    }

    public function test_send_creates_pending_invite_and_queues_one_mail(): void
    {
        Mail::fake();
        $inviter = $this->user('inviter@example.com');

        $invite = $this->service()->send('Friend@Example.com', $inviter);

        $this->assertSame(Invitation::STATUS_PENDING, $invite->status);
        $this->assertSame('friend@example.com', $invite->recipient, 'recipient normalized');
        $this->assertNotNull($invite->sent_at);
        Mail::assertQueued(InvitationMail::class, 1);
        $this->assertSame(1, InviteAnalyticsEvent::where('event_type', InviteAnalyticsEvent::TYPE_INVITE_SENT)->count());
    }

    public function test_send_is_idempotent_for_a_pending_recipient(): void
    {
        Mail::fake();
        $inviter = $this->user('inviter2@example.com');

        $first = $this->service()->send('dup@example.com', $inviter);
        $second = $this->service()->send('dup@example.com', $inviter);

        $this->assertSame($first->id, $second->id, 'same pending invite returned');
        $this->assertSame(1, Invitation::where('recipient', 'dup@example.com')->count());
        Mail::assertQueued(InvitationMail::class, 1); // not re-mailed
    }

    public function test_accept_marks_accepted_and_drops_pending_count(): void
    {
        Mail::fake();
        $invite = $this->service()->send('joiner@example.com', $this->user('i3@example.com'));

        $this->assertSame(1, $this->service()->pendingCountFor('joiner@example.com'));

        $accepted = $this->service()->accept($invite->token);

        $this->assertNotNull($accepted);
        $this->assertSame(Invitation::STATUS_ACCEPTED, $accepted->status);
        $this->assertSame(0, $this->service()->pendingCountFor('joiner@example.com'));
    }

    public function test_expired_invite_cannot_be_accepted(): void
    {
        Mail::fake();
        $invite = $this->service()->send('late@example.com', $this->user('i4@example.com'));
        $invite->update(['expires_at' => Carbon::now()->subDay()]);

        $result = $this->service()->accept($invite->token);

        $this->assertNull($result);
        $this->assertSame(Invitation::STATUS_EXPIRED, $invite->refresh()->status);
    }

    public function test_bounce_suppresses_and_flags(): void
    {
        Mail::fake();
        $invite = $this->service()->send('bouncer@example.com', $this->user('i5@example.com'));

        $this->service()->bounce($invite);

        $this->assertSame(Invitation::STATUS_BOUNCED, $invite->refresh()->status);
        $this->assertSame(1, AbuseSignal::where('subject_type', AbuseSignal::SUBJECT_EMAIL)->count());
    }

    public function test_expire_due_sweeps_pending_past_expiry(): void
    {
        Mail::fake();
        $invite = $this->service()->send('sweep@example.com', $this->user('i6@example.com'));
        $invite->update(['expires_at' => Carbon::now()->subDay()]);

        $count = $this->service()->expireDue();

        $this->assertSame(1, $count);
        $this->assertSame(Invitation::STATUS_EXPIRED, $invite->refresh()->status);
    }

    private function user(string $email): User
    {
        return User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('secret123')]);
    }
}
