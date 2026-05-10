<?php

declare(strict_types=1);

namespace App\Pii\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Padosoft\PiiRedactor\RedactorEngine;
use Throwable;

/**
 * v4.3/W1 sub-PR 4.5 — A2 — Failed-jobs payload sanitiser.
 *
 * Subscribes to `Illuminate\Queue\Events\JobFailed` (auto-fired by the
 * framework after Laravel writes a row into `failed_jobs`). The
 * listener re-reads the just-inserted `failed_jobs.payload` row and
 * rewrites it through the redactor.
 *
 * Why post-insert rather than pre-insert: Laravel's failed-job pipeline
 * inserts into `failed_jobs` BEFORE the `JobFailed` event fires (it is
 * the framework's documented event hook). There is no pre-insert hook
 * we can wire into without monkey-patching `DatabaseFailedJobProvider`.
 * The rewrite-after-insert is therefore the canonical integration
 * point.
 *
 * The `payload` column is JSON-serialised constructor args + queue
 * metadata. We decode → walk → redact every string value → re-encode.
 * The structural keys (job class, retry count, etc.) are preserved
 * untouched because only string values get redacted.
 *
 * Gated by `kb.pii_redactor.enabled` AND `kb.pii_redactor.redact_failed_jobs`.
 *
 * R14 inversion: failures here log + return. The original `failed_jobs`
 * row is preserved (degraded but not lost). A clean queue MUST NOT
 * depend on the redactor being healthy.
 */
final class RedactFailedJobPayload
{
    public function __construct(private readonly RedactorEngine $engine) {}

    public function handle(JobFailed $event): void
    {
        if (! $this->shouldRedact()) {
            return;
        }

        try {
            if (! Schema::hasTable('failed_jobs')) {
                return;
            }

            // Locate the failed-job row that the framework just inserted.
            // Laravel writes the row with `failed_jobs.uuid` set to the
            // job's stable UUID (extracted from the framework-emitted
            // payload envelope). Matching deterministically on uuid
            // avoids the multi-worker race where a sibling job fails on
            // the same (connection, queue) tuple between the framework
            // insert and this listener query — `orderByDesc('id')` would
            // then pick the wrong row, leaving the original unredacted
            // and corrupting the sibling.
            //
            // If the uuid cannot be extracted (older payload formats,
            // synchronous queue driver edge cases) we fall back to the
            // latest-by-(connection, queue) heuristic — degraded but
            // still better than skipping the redaction altogether.
            $uuid = $this->extractJobUuid($event);

            $query = DB::table('failed_jobs')
                ->where('connection', $event->connectionName)
                ->where('queue', $event->job->getQueue() ?? 'default');

            if ($uuid !== null) {
                $query->where('uuid', $uuid);
            } else {
                $query->orderByDesc('id');
            }

            $row = $query->first();

            if ($row === null) {
                return;
            }

            $rawPayload = (string) $row->payload;
            if ($rawPayload === '') {
                return;
            }

            $decoded = json_decode($rawPayload, true);
            if (! is_array($decoded)) {
                return;
            }

            $redacted = $this->redactArrayValues($decoded);

            // Also redact the exception column (separately persisted as
            // a TEXT column by the framework, not nested in the JSON).
            $exception = $row->exception ?? null;
            $exceptionRedacted = is_string($exception) && $exception !== ''
                ? $this->engine->redact($exception)
                : $exception;

            $encoded = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                return;
            }

            DB::table('failed_jobs')
                ->where('id', $row->id)
                ->update([
                    'payload' => $encoded,
                    'exception' => $exceptionRedacted,
                ]);
        } catch (Throwable $e) {
            Log::warning('RedactFailedJobPayload listener failed; original failed_jobs row kept.', [
                'connection' => $event->connectionName,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort extraction of the job UUID from the framework-emitted
     * raw payload envelope. Laravel's queue payload always carries a
     * top-level `uuid` key (set by `Illuminate\Queue\Queue::createPayload`)
     * which is also written into `failed_jobs.uuid`. We never rely on
     * this for correctness — the caller falls back to the latest-row
     * heuristic when extraction returns null.
     */
    private function extractJobUuid(JobFailed $event): ?string
    {
        try {
            $raw = $event->job->getRawBody();
            if ($raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return null;
            }
            $uuid = $decoded['uuid'] ?? null;

            return is_string($uuid) && $uuid !== '' ? $uuid : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function shouldRedact(): bool
    {
        return (bool) config('kb.pii_redactor.enabled', false)
            && (bool) config('kb.pii_redactor.redact_failed_jobs', false);
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
