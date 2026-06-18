<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Queued invitation email (docs/12-notifications.md). ShouldQueue so the send
 * never blocks the request; the accept URL carries the high-entropy token.
 * The InvitationService guards idempotency (one send per pending invitation),
 * so a job retry / duplicate domain event does not double-mail.
 */
final class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Invitation $invitation,
        public readonly string $acceptUrl,
    ) {
    }

    public function build(): self
    {
        return $this->subject('You have been invited')
            ->html(sprintf(
                '<p>You have been invited. Use this link to accept:</p><p><a href="%s">%s</a></p>',
                e($this->acceptUrl),
                e($this->acceptUrl),
            ));
    }
}
