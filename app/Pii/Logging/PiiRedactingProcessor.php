<?php

declare(strict_types=1);

namespace App\Pii\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Padosoft\PiiRedactor\RedactorEngine;
use Throwable;

/**
 * v4.3/W1 sub-PR 4.5 — A1 — Monolog processor that redacts PII out of
 * every log record BEFORE the line hits any handler (file / stderr /
 * Slack / Sentry).
 *
 * Pushed onto every configured Monolog handler by
 * `App\Providers\PiiBoundaryCoverageServiceProvider::registerLogProcessor()`
 * when both `kb.pii_redactor.enabled` AND `kb.pii_redactor.redact_logs`
 * are true.
 *
 * Scope:
 *   - The `message` field (string).
 *   - Every string value inside `context` (and recursively inside
 *     nested arrays).
 *   - Every string value inside `extra`.
 *
 * Defence-in-depth philosophy: even if a developer writes
 * `Log::info('user ' . $request->input('email'))`, the disk record
 * carries `user [email]` (or `[tok:email:abc123]` under tokenise).
 *
 * Performance note: redaction adds detector cost per log line. Default
 * OFF; hosts with very high log throughput should benchmark before
 * enabling. The processor is pure — no Eloquent / cache touch.
 *
 * R14 inversion: if the redactor itself throws, the original record is
 * returned unchanged. Logging MUST NOT loop into itself on failure.
 */
final class PiiRedactingProcessor implements ProcessorInterface
{
    public function __construct(private readonly RedactorEngine $engine) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            $message = $record->message;
            $context = $this->redactArrayValues($record->context);
            $extra = $this->redactArrayValues($record->extra);

            if ($message !== '') {
                $message = $this->engine->redact($message);
            }

            return $record->with(
                message: $message,
                context: $context,
                extra: $extra,
            );
        } catch (Throwable) {
            // Logging is the last line of defence — never let a redactor
            // failure short-circuit the log call. The unredacted record
            // is still safer than no record at all.
            return $record;
        }
    }

    /**
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
