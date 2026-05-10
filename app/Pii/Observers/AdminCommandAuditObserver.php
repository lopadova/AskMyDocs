<?php

declare(strict_types=1);

namespace App\Pii\Observers;

use App\Models\AdminCommandAudit;
use Illuminate\Support\Facades\Log;
use Padosoft\PiiRedactor\RedactorEngine;
use Throwable;

/**
 * v4.3/W1 sub-PR 4.5 — A5 — AdminCommandAudit `creating` observer.
 *
 * Operators sometimes pass paths / IDs / email addresses as command
 * arguments (e.g. `kb:delete --owner-email=foo@bar.com docs/clients/foo.md`).
 * The audit row stores those args verbatim in `args_json`, the captured
 * stdout in `stdout_head`, and any exception text in `error_message`.
 * All three columns can therefore leak PII into the immutable forensic
 * trail.
 *
 * This observer redacts those three columns on `creating` events when
 * `kb.pii_redactor.enabled` AND `kb.pii_redactor.redact_command_audit`
 * are both true. The operator identity columns (`user_id`, `client_ip`,
 * `user_agent`) are deliberately NOT redacted — the audit trail is
 * useless without them.
 *
 * R14 inversion: redactor failures log + pass through. Audit rows MUST
 * exist; a redactor outage cannot suppress the security trail.
 */
final class AdminCommandAuditObserver
{
    public function __construct(private readonly RedactorEngine $engine) {}

    public function creating(AdminCommandAudit $audit): void
    {
        if (! $this->shouldRedact()) {
            return;
        }

        try {
            $stdout = $audit->getAttribute('stdout_head');
            if (is_string($stdout) && $stdout !== '') {
                $audit->setAttribute('stdout_head', $this->engine->redact($stdout));
            }

            $error = $audit->getAttribute('error_message');
            if (is_string($error) && $error !== '') {
                $audit->setAttribute('error_message', $this->engine->redact($error));
            }

            $args = $audit->getAttribute('args_json');
            if (is_array($args)) {
                $audit->setAttribute('args_json', $this->redactArrayValues($args));
            }
        } catch (Throwable $e) {
            Log::warning('AdminCommandAuditObserver redaction failed; original values kept.', [
                'command' => $audit->getAttribute('command'),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function shouldRedact(): bool
    {
        return (bool) config('kb.pii_redactor.enabled', false)
            && (bool) config('kb.pii_redactor.redact_command_audit', false);
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
