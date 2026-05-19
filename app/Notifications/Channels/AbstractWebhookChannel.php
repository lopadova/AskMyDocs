<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Jobs\SendExternalNotificationJob;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;
use Illuminate\Support\Carbon;

/**
 * v8.0/W2.1 — base class for external webhook-based channels.
 *
 * The 4 W2.1 channels (Discord, Slack, Teams, generic Webhook) share
 * the same lifecycle:
 *
 *   1. `send()` resolves the recipient URL from `config()`. Missing
 *      config → append a `'skipped'` log entry and return early.
 *      We do NOT throw — operators run with some channels disabled
 *      on purpose, and a misconfigured channel must not break the
 *      whole dispatch.
 *   2. `send()` builds the per-channel payload via the abstract
 *      {@see buildPayload()} hook (Discord embeds, Slack blocks,
 *      Teams adaptive card, generic JSON).
 *   3. `send()` dispatches {@see SendExternalNotificationJob} to the
 *      queue with the URL + payload + optional HMAC secret. The job
 *      handles the HTTP POST, retries, and final delivery-log
 *      append. The channel records `'queued'` immediately so the
 *      audit row always reflects an outcome.
 *
 * The queueable indirection is on purpose: external HTTP can take
 * up to 10s per attempt × `$tries` = 155s of accumulated backoff
 * in the worst case, which is unacceptable to do synchronously
 * inside `DB::afterCommit` (the dispatcher's callsite). Mirroring
 * EmailChannel's `Mail::to->queue(...)` pattern keeps the dispatch
 * thread non-blocking.
 */
abstract class AbstractWebhookChannel implements NotificationChannelInterface
{
    /**
     * The config key under which the channel's URL + secret live.
     * Subclasses return e.g. `'askmydocs.notifications.channels.discord'`;
     * the channel then reads `.url` (required) and `.secret`
     * (optional, only used by the generic Webhook channel for HMAC).
     */
    abstract protected function configKey(): string;

    /**
     * Build the per-channel JSON payload from the event + recipient.
     *
     * Return value MUST be a JSON-encodable array (no Closure / no
     * Eloquent models). The job dispatches it via `Http::post()`
     * which json_encodes the body before sending.
     *
     * @return array<string, mixed>
     */
    abstract protected function buildPayload(BaseNotificationEvent $event, ?User $user): array;

    public function send(
        BaseNotificationEvent $event,
        ?User $user,
        NotificationEvent $eventRow,
    ): void {
        $url = $this->resolveUrl();

        if ($url === null) {
            $this->appendLog($eventRow, 'skipped', 'channel not configured');
            return;
        }

        $payload = $this->buildPayload($event, $user);
        $secret = $this->resolveSecret();

        SendExternalNotificationJob::dispatch(
            channelName: $this->name(),
            eventRowId: (int) $eventRow->id,
            url: $url,
            payload: $payload,
            hmacSecret: $secret,
        );

        $this->appendLog($eventRow, 'queued');
    }

    /**
     * Resolve the destination URL from config. Returns null when
     * the channel is not configured (caller decides what to do).
     */
    protected function resolveUrl(): ?string
    {
        $url = (string) config($this->configKey().'.url', '');
        return $url === '' ? null : $url;
    }

    /**
     * Resolve the optional HMAC secret for the generic Webhook
     * channel (Slack / Discord / Teams use their own webhook-id
     * authentication baked into the URL). Returns null when no
     * secret is configured.
     */
    protected function resolveSecret(): ?string
    {
        $secret = (string) config($this->configKey().'.secret', '');
        return $secret === '' ? null : $secret;
    }

    /**
     * Append one `{channel, status, at, error?}` entry to the
     * passed-in row's `channel_dispatch_log`. Mirrors the helper in
     * EmailChannel + InAppChannel (intentional copy until a v8.x
     * refactor pulls them into a shared trait — keeping W2.1 PR
     * surface tight by not touching W1 code).
     */
    protected function appendLog(NotificationEvent $eventRow, string $status, ?string $error = null): void
    {
        $log = $eventRow->channel_dispatch_log ?? [];
        $entry = [
            'channel' => $this->name(),
            'status' => $status,
            'at' => Carbon::now()->toIso8601String(),
        ];
        if ($error !== null) {
            $entry['error'] = $error;
        }
        $log[] = $entry;
        $eventRow->channel_dispatch_log = $log;
        $eventRow->save();
    }
}
