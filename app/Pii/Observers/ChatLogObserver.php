<?php

declare(strict_types=1);

namespace App\Pii\Observers;

use App\Models\ChatLog;
use Illuminate\Support\Facades\Log;
use Padosoft\PiiRedactor\RedactorEngine;
use Throwable;

/**
 * v4.3/W1 sub-PR 4.5 — A4 — ChatLog model `creating` observer.
 *
 * v4.1 only redacted `chat_logs.question` (transitively, via the
 * `RedactChatPii` middleware that ran on the request body before the
 * controller persisted it). This observer extends coverage to two more
 * columns that may carry PII:
 *
 *   - `chat_logs.answer` — the LLM output may quote PII present in the
 *     ingested corpus (a citation snippet, a verbatim email address
 *     from a document).
 *   - `chat_logs.sources` (JSON) — citation snippets persisted alongside
 *     the answer for the admin chat-log drawer. The drawer surfaces in
 *     the operator UI, so a leaked snippet shows up in the admin
 *     dashboard.
 *
 * Gated by `kb.pii_redactor.enabled` AND new `kb.pii_redactor.redact_answers`
 * — kept separate from `persist_chat_redacted` because some hosts may
 * want to redact the user input but NOT the LLM output (e.g. when the
 * provider already enforces output filtering).
 *
 * R14 inversion: redactor failures log + pass through.
 */
final class ChatLogObserver
{
    public function __construct(private readonly RedactorEngine $engine) {}

    public function creating(ChatLog $log): void
    {
        if (! $this->shouldRedact()) {
            return;
        }

        try {
            $answer = $log->getAttribute('answer');
            if (is_string($answer) && $answer !== '') {
                $log->setAttribute('answer', $this->engine->redact($answer));
            }

            $sources = $log->getAttribute('sources');
            if (is_array($sources)) {
                $log->setAttribute('sources', $this->redactArrayValues($sources));
            }
        } catch (Throwable $e) {
            Log::warning('ChatLogObserver redaction failed; original values kept.', [
                'session_id' => $log->getAttribute('session_id'),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function shouldRedact(): bool
    {
        return (bool) config('kb.pii_redactor.enabled', false)
            && (bool) config('kb.pii_redactor.redact_answers', false);
    }

    /**
     * Walk a nested array (sources / citations are typically a list of
     * arrays with a `snippet` or `text` field) and redact every string
     * value in place. Numeric / boolean / null values are left alone.
     *
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private function redactArrayValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value) && $value !== '') {
                $values[$key] = $this->engine->redact($value);
                continue;
            }
            if (is_array($value)) {
                $values[$key] = $this->redactArrayValues($value);
            }
        }

        return $values;
    }
}
