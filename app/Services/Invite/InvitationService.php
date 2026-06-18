<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Mail\InvitationMail;
use App\Models\AbuseSignal;
use App\Models\InviteAnalyticsEvent;
use App\Models\Invitation;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invitation send/accept lifecycle + notification dispatch
 * (docs/12-notifications.md). Tenant-scoped (R30).
 *
 * Sending is idempotent: one PENDING invitation per (tenant, recipient,
 * context_ref). A repeat send returns the existing pending row and does NOT
 * re-mail, so a duplicate domain event or job retry collapses to a single send.
 * Bounce feedback transitions the invitation to `bounced` and raises an
 * AbuseSignal(email). The pending-count drops to zero the instant an invite
 * leaves `pending`.
 */
final class InvitationService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AnalyticsTracker $analytics,
    ) {
    }

    /**
     * @param  array{channel?: string, context_ref?: string|null, role?: string|null, code_id?: int|null}  $options
     */
    public function send(string $recipient, User $inviter, array $options = []): Invitation
    {
        $tenantId = $this->tenant->current();
        $recipient = $this->normalizeRecipient($recipient);
        $contextRef = $options['context_ref'] ?? null;

        // Idempotency anchor — a pending invite for this (recipient, context)
        // already exists → return it, do not re-mail.
        $existing = Invitation::query()
            ->forTenant($tenantId)
            ->where('recipient', $recipient)
            ->where('context_ref', $contextRef)
            ->where('status', Invitation::STATUS_PENDING)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $invitation = Invitation::create([
            'tenant_id' => $tenantId,
            'code_id' => $options['code_id'] ?? null,
            'token' => $this->mintToken(),
            'channel' => $options['channel'] ?? Invitation::CHANNEL_EMAIL,
            'recipient' => $recipient,
            'inviter_id' => $inviter->id,
            'context_ref' => $contextRef,
            'role' => $options['role'] ?? null,
            'status' => Invitation::STATUS_PENDING,
            'expires_at' => Carbon::now()->addDays((int) config('invite.invitation_ttl_days', 7)),
            'sent_at' => Carbon::now(),
        ]);

        $this->analytics->record(InviteAnalyticsEvent::TYPE_INVITE_CREATED, "invite_created:{$invitation->id}",
            ['account_id' => $inviter->id, 'code_id' => $invitation->code_id]);

        if ($invitation->channel === Invitation::CHANNEL_EMAIL) {
            Mail::to($recipient)->queue(new InvitationMail($invitation, $this->acceptUrl($invitation)));
        }

        $this->analytics->record(InviteAnalyticsEvent::TYPE_INVITE_SENT, "invite_sent:{$invitation->id}",
            ['account_id' => $inviter->id, 'code_id' => $invitation->code_id]);

        return $invitation;
    }

    /**
     * Accept an invitation by token. Returns the accepted Invitation, or null
     * when the token is unknown / already-resolved / expired (the row is
     * transitioned to `expired` in the latter case).
     */
    public function accept(string $token, ?User $accepter = null): ?Invitation
    {
        $invitation = Invitation::query()
            ->forTenant($this->tenant->current())
            ->where('token', $token)
            ->first();

        if ($invitation === null || $invitation->status !== Invitation::STATUS_PENDING) {
            return null;
        }

        if ($invitation->isExpired()) {
            $invitation->update(['status' => Invitation::STATUS_EXPIRED]);

            return null;
        }

        $invitation->update([
            'status' => Invitation::STATUS_ACCEPTED,
            'accepted_at' => Carbon::now(),
        ]);

        $this->analytics->record(InviteAnalyticsEvent::TYPE_INVITE_OPENED, "invite_opened:{$invitation->id}",
            ['account_id' => $accepter?->id, 'code_id' => $invitation->code_id]);

        return $invitation->refresh();
    }

    /**
     * Record a hard bounce: transition to `bounced` and raise an
     * AbuseSignal(email) so a suppressed recipient is never re-mailed.
     */
    public function bounce(Invitation $invitation): Invitation
    {
        $invitation->update(['status' => Invitation::STATUS_BOUNCED]);

        AbuseSignal::create([
            'tenant_id' => $this->tenant->current(),
            'subject_type' => AbuseSignal::SUBJECT_EMAIL,
            'subject_value' => hash_hmac('sha256', $invitation->recipient, (string) (config('invite.pii.hash_salt') ?? config('app.key'))),
            'signal_type' => AbuseSignal::TYPE_BLACKLIST,
            'severity' => AbuseSignal::SEVERITY_WARN,
            'action_taken' => AbuseSignal::ACTION_FLAG,
            'context' => ['reason' => 'hard_bounce'],
            'created_at' => Carbon::now(),
        ]);

        return $invitation->refresh();
    }

    /**
     * Count pending invitations for a recipient (in-app badge). Drops to zero
     * the instant the invite leaves `pending`.
     */
    public function pendingCountFor(string $recipient): int
    {
        return Invitation::query()
            ->forTenant($this->tenant->current())
            ->where('recipient', $this->normalizeRecipient($recipient))
            ->where('status', Invitation::STATUS_PENDING)
            ->count();
    }

    /**
     * Sweep pending invitations past their expiry → `expired`. Returns the
     * number transitioned (driven by the Phase 6 scheduler).
     */
    public function expireDue(): int
    {
        return Invitation::query()
            ->forTenant($this->tenant->current())
            ->where('status', Invitation::STATUS_PENDING)
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => Invitation::STATUS_EXPIRED]);
    }

    private function normalizeRecipient(string $recipient): string
    {
        return strtolower(trim($recipient));
    }

    private function mintToken(): string
    {
        // High-entropy bearer token: token_bytes of CSPRNG, hex-encoded.
        return bin2hex(random_bytes((int) config('invite.token_bytes', 32)));
    }

    private function acceptUrl(Invitation $invitation): string
    {
        return rtrim((string) config('app.url'), '/') . '/invite/accept?token=' . $invitation->token;
    }
}
