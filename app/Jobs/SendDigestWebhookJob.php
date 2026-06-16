<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.15/W2 — POSTs a rendered digest card to a team channel webhook
 * (Discord / Slack / Teams / generic Webhook).
 *
 * Mirrors {@see SendExternalNotificationJob}'s transport posture (same one-shot
 * JSON encode, optional HMAC, 10s timeout, retry/backoff on 429/5xx) but is
 * decoupled from the per-event `notification_events` audit log — a digest is a
 * tenant-level broadcast, not a per-recipient event. Terminal failures are
 * logged (R14); they never break the digest run.
 */
final class SendDigestWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    private const REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $channelName,
        public readonly string $tenantId,
        public readonly string $url,
        public readonly array $payload,
        public readonly ?string $hmacSecret = null,
    ) {
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(): void
    {
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
            return;
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            // Transient — bubble up so Laravel re-queues with backoff().
            throw new \RuntimeException("HTTP {$status} from digest channel {$this->channelName}");
        }

        // Non-retryable client error — log loudly and stop (R14).
        Log::warning('SendDigestWebhookJob: non-retryable channel response', [
            'channel' => $this->channelName,
            'tenant_id' => $this->tenantId,
            'status' => $status,
            'body' => substr((string) $response->body(), 0, 200),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('SendDigestWebhookJob: delivery failed after retries', [
            'channel' => $this->channelName,
            'tenant_id' => $this->tenantId,
            'error' => $exception?->getMessage() ?? 'unknown',
        ]);
    }
}
