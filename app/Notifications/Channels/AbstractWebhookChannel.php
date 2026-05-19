<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Jobs\SendExternalNotificationJob;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;
use App\Notifications\NotificationEventLogger;

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
 * The queueable indirection is on purpose: each HTTP attempt has a
 * 10s timeout and `$tries=4` (1 + 3 retries), so a single send can
 * accumulate up to ~40s of in-flight HTTP plus the
 * `[5, 30, 120]s = 155s` backoff between retries — ~195s worst case.
 * That latency is unacceptable to do synchronously inside
 * `DB::afterCommit` (the dispatcher's callsite). Mirroring
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

        // Log `queued` BEFORE dispatching the job so the chronological
        // order in `channel_dispatch_log` is correct under both async
        // queue (the worker picks up the job seconds later) AND
        // synchronous queue (`QUEUE_CONNECTION=sync` runs `handle()`
        // inline during `dispatch()` — without the pre-log the
        // `delivered`/`failed` entry would land BEFORE the `queued`
        // entry, which is confusing for operators tailing the row).
        $this->appendLog($eventRow, 'queued');

        SendExternalNotificationJob::dispatch(
            channelName: $this->name(),
            eventRowId: (int) $eventRow->id,
            url: $url,
            payload: $payload,
            hmacSecret: $secret,
        );
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
     * Append one `{channel, status, at, error?}` entry atomically.
     *
     * Delegates to {@see NotificationEventLogger::append()} so the
     * write goes through a `lockForUpdate` + `update` inside a
     * single transaction (R21). This protects against the lost-
     * update race that arises under `QUEUE_CONNECTION=sync`:
     * `SendExternalNotificationJob::handle()` runs inline during
     * `dispatch()` and appends its own `delivered`/`failed` entry
     * against a fresh DB load — if this method then mutated the
     * stale in-memory `$eventRow` and saved, the job's entry would
     * be overwritten.
     *
     * Passing `$eventRow` along refreshes its in-memory
     * `channel_dispatch_log` so any subsequent helper call (from
     * the next adapter in the dispatcher's loop) starts from a
     * non-stale view.
     */
    protected function appendLog(NotificationEvent $eventRow, string $status, ?string $error = null): void
    {
        NotificationEventLogger::append(
            eventRowId: (int) $eventRow->getKey(),
            channel: $this->name(),
            status: $status,
            error: $error,
            inMemoryRow: $eventRow,
        );
    }
}
