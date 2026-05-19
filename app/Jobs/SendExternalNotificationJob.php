<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.0/W2.1 — Generic queueable job that POSTs an external
 * notification payload (Discord / Slack / Teams / generic Webhook)
 * and records the delivery outcome in
 * `notification_events.channel_dispatch_log` for the originating row.
 *
 * Each invocation handles ONE channel × ONE row × ONE recipient.
 * The channel adapter (subclass of {@see \App\Notifications\Channels\AbstractWebhookChannel})
 * dispatches the job and records a `'queued'` entry immediately;
 * the queue worker subsequently appends either `'delivered'` (HTTP
 * 2xx) or `'failed'` (transient 4xx/5xx after `$tries` retries
 * exhausted) on the same row.
 *
 * The job runs inside `DB::transaction` + `lockForUpdate()` (R21
 * atomic invariant: read + write in the same closure) so concurrent
 * jobs writing to the same `channel_dispatch_log` JSON array on
 * different channels never lose append entries. SQLite tests don't
 * enforce row-level locking but the call-site posture stays the
 * same — what matters is that the read AND the write happen in the
 * same transaction so production Postgres serialises the append.
 *
 * Failure modes (R14 surface failures loudly):
 *   - URL never resolves        → `failed` entry with the error message
 *   - HTTP 4xx (non-429)        → `failed` entry, no retry (client bug,
 *                                 retrying won't fix it)
 *   - HTTP 429 / 5xx / network  → throws, job retries with backoff
 *                                 [5, 30, 120] seconds (matching
 *                                 PLAN-v8.0 §C.2 W2.1 acceptance gate)
 *   - retries exhausted         → {@see failed()} appends a final
 *                                 `failed` entry so the audit row
 *                                 always carries an outcome
 *
 * The original `notification_events` row is identified by id — we
 * don't serialise the model itself (R3 memory-safe) because the row
 * may be soft-deleted or pruned by `notifications:prune` (W1.5)
 * between dispatch and queue execution; the job is tolerant of that
 * race and simply no-ops with a warning log if the row is gone.
 */
final class SendExternalNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * 1 initial attempt + 3 retries.
     */
    public int $tries = 4;

    /**
     * Per-attempt backoff seconds — matches plan §C.2.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    /**
     * Hard ceiling for any single POST attempt — 10s is enough for
     * a webhook handshake; longer than that and we'd rather retry
     * than block the queue worker.
     */
    private const REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $channelName,
        public readonly int $eventRowId,
        public readonly string $url,
        public readonly array $payload,
        public readonly ?string $hmacSecret = null,
    ) {
    }

    public function handle(): void
    {
        // Headers + optional HMAC signature. The signature is over
        // the JSON-encoded payload bytes the recipient will see, so
        // verifiers can re-compute deterministically.
        $headers = ['Content-Type' => 'application/json'];
        if ($this->hmacSecret !== null && $this->hmacSecret !== '') {
            $body = json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $headers['X-AskMyDocs-Signature'] = 'sha256='.hash_hmac('sha256', $body, $this->hmacSecret);
        }

        $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withHeaders($headers)
            ->post($this->url, $this->payload);

        if ($response->successful()) {
            $this->appendLog('delivered');
            return;
        }

        $status = $response->status();

        // Retry on 429 (rate limit) + 5xx (server-side transient).
        // 4xx (other than 429) is a client bug — retrying will not
        // fix it, so we surface immediately as `failed` and return
        // (no throw, no retry).
        if ($status === 429 || $status >= 500) {
            $this->release($this->backoffSeconds());
            // Throw so Laravel records the attempt and runs `failed()`
            // once `$tries` is exhausted.
            $error = "HTTP {$status} from external notification channel";
            throw new \RuntimeException($error);
        }

        $this->appendLog('failed', "HTTP {$status} (non-retryable): ".substr((string) $response->body(), 0, 200));
    }

    /**
     * Laravel calls this once `$tries` is exhausted. We use it to
     * record the terminal failure in the audit log so the row never
     * stays in the ambiguous `queued` state forever.
     */
    public function failed(?Throwable $exception): void
    {
        $error = $exception !== null ? $exception->getMessage() : 'unknown failure';
        $this->appendLog('failed', "retries exhausted: {$error}");
    }

    /**
     * Determine the backoff delay for the *current* attempt. Laravel
     * provides `$this->attempts()` 1-indexed; we want index 0 of the
     * backoff() array on the first failure → second attempt.
     */
    private function backoffSeconds(): int
    {
        $backoff = $this->backoff();
        $attempt = max(1, $this->attempts());
        $index = min(count($backoff) - 1, $attempt - 1);
        return $backoff[$index];
    }

    /**
     * Atomically append a `{channel, status, at, error?}` entry to
     * the originating row's `channel_dispatch_log`. R21 holds because
     * the lock + write happen in the same transaction closure.
     */
    private function appendLog(string $status, ?string $error = null): void
    {
        try {
            DB::transaction(function () use ($status, $error): void {
                /** @var NotificationEvent|null $row */
                $row = NotificationEvent::query()
                    ->where('id', $this->eventRowId)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    Log::warning(
                        'SendExternalNotificationJob: target notification row missing',
                        [
                            'event_row_id' => $this->eventRowId,
                            'channel' => $this->channelName,
                            'status' => $status,
                        ],
                    );
                    return;
                }

                $log = $row->channel_dispatch_log ?? [];
                $entry = [
                    'channel' => $this->channelName,
                    'status' => $status,
                    'at' => Carbon::now()->toIso8601String(),
                ];
                if ($error !== null) {
                    $entry['error'] = $error;
                }
                $log[] = $entry;
                $row->channel_dispatch_log = $log;
                $row->save();
            });
        } catch (Throwable $e) {
            Log::error(
                'SendExternalNotificationJob: failed to write channel_dispatch_log',
                [
                    'event_row_id' => $this->eventRowId,
                    'channel' => $this->channelName,
                    'status' => $status,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }
}
