<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Logs;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase H1 — admin Log Viewer, failed-jobs tab.
 *
 * Reads straight off the `failed_jobs` table (Laravel's default shape).
 * We parse the `payload` JSON so the SPA can render the queue name /
 * displayName / uuid without re-parsing on every row.
 *
 * The exception string is large (full trace) — we expose it in full
 * because a drawer / expandable row needs the whole thing. Size risk
 * is bounded by the queue (retries exhausted → one big row ~100 KB max).
 *
 * NO write path here (no retry, no forget) — H1 is read-only; retry +
 * bulk delete land in H2 under the maintenance wizard.
 *
 * @property-read object $resource  stdClass from DB::table('failed_jobs')
 */
class FailedJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var object $job */
        $job = $this->resource;

        $payload = $this->decodePayload($job->payload ?? null);

        return [
            'id' => (int) $job->id,
            'uuid' => $job->uuid ?? null,
            'connection' => $job->connection ?? null,
            'queue' => $job->queue ?? null,
            'display_name' => $payload['displayName'] ?? null,
            'job_class' => $this->resolveJobClass($payload),
            'attempts' => $payload['attempts'] ?? null,
            'exception' => $job->exception ?? null,
            'failed_at' => isset($job->failed_at) ? (string) $job->failed_at : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function decodePayload(?string $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Surface the underlying job FQCN from the serialized payload so
     * operators can see `App\Jobs\IngestDocumentJob` at a glance.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function resolveJobClass(?array $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }
        if (isset($payload['data']['commandName']) && is_string($payload['data']['commandName'])) {
            return $payload['data']['commandName'];
        }

        return $payload['displayName'] ?? null;
    }
}
