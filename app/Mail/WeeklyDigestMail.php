<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * v8.7/W2 — queued weekly-digest Mailable.
 *
 * Carries a flat, per-user roundup (event-type groups with counts +
 * sample titles) so the queued job payload stays small. Rendered by
 * `NotificationsDigestWeeklyCommand`.
 *
 * @see \App\Console\Commands\NotificationsDigestWeeklyCommand
 */
final class WeeklyDigestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{event_type: string, label: string, count: int, samples: list<string>}>  $groups
     */
    public function __construct(
        public readonly string $weekStartDate,
        public readonly array $groups,
    ) {
    }

    public function envelope(): Envelope
    {
        $total = array_sum(array_map(static fn (array $g): int => (int) $g['count'], $this->groups));

        return new Envelope(
            subject: "Your AskMyDocs weekly digest — {$total} update".($total === 1 ? '' : 's'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weekly-digest',
            with: [
                'weekStartDate' => $this->weekStartDate,
                'groups' => $this->groups,
            ],
        );
    }
}
