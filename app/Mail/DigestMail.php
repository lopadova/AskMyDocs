<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Digest\DigestPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * v8.15/W2 — the rich, magazine-grade KB engagement digest email.
 *
 * Distinct from {@see WeeklyDigestMail} (the per-user event roundup): this one
 * carries the metrics, the section lists, and the AI narrative for the whole
 * tenant. Rendered from the channel-agnostic {@see DigestPayload} so the email
 * and the Discord/Slack/Teams cards never drift.
 */
final class DigestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  DigestPayload::toArray()
     */
    public function __construct(public readonly array $payload)
    {
    }

    public static function fromPayload(DigestPayload $payload): self
    {
        return new self($payload->toArray());
    }

    public function envelope(): Envelope
    {
        $frequency = ucfirst((string) ($this->payload['frequency'] ?? 'weekly'));

        return new Envelope(
            subject: "Your AskMyDocs {$frequency} KB digest",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.digest',
            with: ['d' => $this->payload],
        );
    }
}
