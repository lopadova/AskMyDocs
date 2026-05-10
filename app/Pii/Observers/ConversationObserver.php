<?php

declare(strict_types=1);

namespace App\Pii\Observers;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Padosoft\PiiRedactor\RedactorEngine;
use Throwable;

/**
 * v4.3/W1 sub-PR 4.5 — A3 — Conversations model save observer.
 *
 * Redacts the `title` field on `saving` events when both
 * `kb.pii_redactor.enabled` AND `kb.pii_redactor.persist_chat_redacted`
 * (existing v4.1 knob, reused) are true. Conversation titles are often
 * auto-derived from the first user message, so they can leak the same
 * PII that the chat middleware already redacts on the message body.
 *
 * Defence-in-depth philosophy (R14 inversion): if the redactor itself
 * throws, we log + let the original write proceed. The redactor is a
 * safety net, NOT a load-bearing wall in front of the user-facing
 * persistence path. A redactor outage must NOT break the chat surface.
 */
final class ConversationObserver
{
    public function __construct(private readonly RedactorEngine $engine) {}

    public function saving(Conversation $conversation): void
    {
        if (! $this->shouldRedact()) {
            return;
        }

        $title = $conversation->getAttribute('title');
        if (! is_string($title) || $title === '') {
            return;
        }

        try {
            $conversation->setAttribute('title', $this->engine->redact($title));
        } catch (Throwable $e) {
            Log::warning('ConversationObserver redaction failed; original title kept.', [
                'conversation_id' => $conversation->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function shouldRedact(): bool
    {
        return (bool) config('kb.pii_redactor.enabled', false)
            && (bool) config('kb.pii_redactor.persist_chat_redacted', false);
    }
}
