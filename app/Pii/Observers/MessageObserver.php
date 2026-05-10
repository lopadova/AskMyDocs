<?php

declare(strict_types=1);

namespace App\Pii\Observers;

use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Padosoft\PiiRedactor\RedactorEngine;
use Throwable;

/**
 * v4.3/W1 sub-PR 4.5 — A3 — Messages model save observer.
 *
 * Defence-in-depth backstop for the `content` column on `messages`.
 * The v4.1 `RedactChatPii` middleware already redacts `content` when
 * the request flows through the standard chat routes — but this
 * observer covers EVERY persistence path, including:
 *
 *   - direct `Message::create()` calls in tests / seeders
 *   - background jobs that insert assistant turns
 *   - admin tools that backfill messages
 *
 * Same gates as the middleware (`kb.pii_redactor.enabled` AND
 * `kb.pii_redactor.persist_chat_redacted`) so the two layers stay in
 * lock-step: enabling one without the other would create asymmetric
 * persistence.
 *
 * Idempotency: re-redacting an already-redacted string is a no-op for
 * Mask / Hash / Drop strategies. For Tokenise it produces deterministic
 * tokens (same salt + content → same token), so duplicate observer
 * runs do not pollute `pii_token_maps`.
 *
 * R14 inversion: redactor failures log + pass through. Never block
 * a message insert on a redactor outage.
 */
final class MessageObserver
{
    public function __construct(private readonly RedactorEngine $engine) {}

    public function saving(Message $message): void
    {
        if (! $this->shouldRedact()) {
            return;
        }

        $content = $message->getAttribute('content');
        if (! is_string($content) || $content === '') {
            return;
        }

        try {
            $message->setAttribute('content', $this->engine->redact($content));
        } catch (Throwable $e) {
            Log::warning('MessageObserver redaction failed; original content kept.', [
                'message_id' => $message->getKey(),
                'conversation_id' => $message->getAttribute('conversation_id'),
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
