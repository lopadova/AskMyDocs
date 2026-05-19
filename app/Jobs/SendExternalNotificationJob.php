<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationEvent;
use App\Notifications\NotificationEventLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
 * R30 — `tenant_id` is captured in the constructor at dispatch time
 * (the adapter passes `$eventRow->tenant_id`) and forwarded to every
 * helper call. The helper scopes its `lockForUpdate()` by
 * `(id, tenant_id)`, so a poisoned job payload from one tenant
 * cannot mutate another tenant's audit log.
 *
 * Failure modes (R14 surface failures loudly):
 *   - audit row was pruned    → skip the HTTP POST AND the log
 *                               append (the recipient is gone; we
 *                               don't ship a webhook for an audit
 *                               row that no longer exists)
 *   - URL never resolves      → `failed` entry with the error message
 *   - HTTP 4xx (non-429)      → `failed` entry, no retry (client bug,
 *                               retrying won't fix it)
 *   - HTTP 429 / 5xx / network → throws, job retries with backoff
 *                                [5, 30, 120] seconds (matching
 *                                PLAN-v8.0 §C.2 W2.1 acceptance gate)
 *   - retries exhausted       → {@see failed()} appends a final
 *                               `failed` entry so the audit row
 *                               always carries an outcome
 *
 * The original `notification_events` row is identified by id — we
 * don't serialise the model itself (R3 memory-safe) because the row
 * may be soft-deleted or pruned by `notifications:prune` (W1.5)
 * between dispatch and queue execution; the job is tolerant of that
 * race and explicitly checks row existence upfront (scoped by
 * tenant_id) before performing the HTTP send.
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
        public readonly string $tenantId,
        public readonly string $url,
        public readonly array $payload,
        public readonly ?string $hmacSecret = null,
    ) {
    }

    public function handle(): void
    {
        // Skip the entire send if the originating audit row was
        // pruned (e.g. `notifications:prune` ran between dispatch
        // and queue execution). The row lookup is scoped by
        // tenant_id so a tenant cannot probe another tenant's
        // row existence via job dispatch.
        $rowExists = NotificationEvent::query()
            ->where('id', $this->eventRowId)
            ->where('tenant_id', $this->tenantId)
            ->exists();
        if (! $rowExists) {
            Log::warning('SendExternalNotificationJob: audit row missing, skipping send', [
                'channel' => $this->channelName,
                'event_row_id' => $this->eventRowId,
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        // Serialise the payload ONCE with explicit flags. The same
        // bytes are signed (for HMAC) and shipped on the wire — if
        // we let `Http::post()` re-encode internally with different
        // defaults, the receiver's HMAC verification would fail
        // against the body it actually got.
        $body = json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $headers = ['Content-Type' => 'application/json'];
        if ($this->hmacSecret !== null && $this->hmacSecret !== '') {
            $headers['X-AskMyDocs-Signature'] = 'sha256='.hash_hmac('sha256', $body, $this->hmacSecret);
        }

        $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($this->url);

        if ($response->successful()) {
            NotificationEventLogger::append(
                eventRowId: $this->eventRowId,
                tenantId: $this->tenantId,
                channel: $this->channelName,
                status: 'delivered',
            );
            return;
        }

        $status = $response->status();

        // Retry on 429 (rate limit) + 5xx (server-side transient).
        // 4xx (other than 429) is a client bug — retrying will not
        // fix it, so we surface immediately as `failed` and return
        // (no throw, no retry).
        //
        // For the retryable branch we ONLY throw; Laravel reads the
        // `backoff()` method automatically on uncaught exception
        // and re-queues with the per-attempt delay. Calling
        // `$this->release(...)` here would be redundant (and would
        // cause double-release on some queue drivers).
        if ($status === 429 || $status >= 500) {
            throw new \RuntimeException("HTTP {$status} from external notification channel");
        }

        NotificationEventLogger::append(
            eventRowId: $this->eventRowId,
            tenantId: $this->tenantId,
            channel: $this->channelName,
            status: 'failed',
            error: "HTTP {$status} (non-retryable): ".substr((string) $response->body(), 0, 200),
        );
    }

    /**
     * Laravel calls this once `$tries` is exhausted. We use it to
     * record the terminal failure in the audit log so the row never
     * stays in the ambiguous `queued` state forever.
     */
    public function failed(?Throwable $exception): void
    {
        $error = $exception !== null ? $exception->getMessage() : 'unknown failure';
        NotificationEventLogger::append(
            eventRowId: $this->eventRowId,
            tenantId: $this->tenantId,
            channel: $this->channelName,
            status: 'failed',
            error: "retries exhausted: {$error}",
        );
    }
}
