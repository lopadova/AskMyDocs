<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\NotificationEvent;
use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;

/**
 * v8.0/W1.2 — channel adapter contract (ADR 0012).
 *
 * Each channel adapter (`InAppChannel`, `EmailChannel`,
 * `DiscordChannel`, `SlackChannel`, `TeamsChannel`,
 * `WebhookChannel`) implements `send()`. The contract:
 *
 *  - The adapter NEVER inserts rows into `notification_events`
 *    (that ownership belongs to `NotificationDispatcher`).
 *  - The adapter MUST append exactly ONE entry to the existing
 *    `notification_events.channel_dispatch_log` array for the
 *    row identified by `$eventRow`. The entry shape is
 *    `['channel' => <name>, 'status' => 'delivered'|'queued'|'skipped',
 *    'at' => <iso-8601>, 'error?' => <msg>]`.
 *  - **Failure-log ownership.** The adapter either (a) catches
 *    its own error internally and appends a `status: 'failed'`
 *    entry, OR (b) throws and lets the dispatcher catch + append
 *    the single fallback `failed` entry. The adapter MUST NOT
 *    do BOTH (log a `failed` entry AND throw) — that would
 *    double-log the channel. Adapters that prefer the
 *    throw-and-let-the-dispatcher-log model can do so without
 *    extra wiring; the dispatcher's `try/catch` calls
 *    `appendFailureLog()` on throw.
 *  - The dispatcher serialises adapter invocations per
 *    `$eventRow` (see ADR 0012 §Consequences — channel_dispatch_log
 *    append concurrency) so adapters can assume no other writer
 *    races them on the same row.
 *
 * `$user` is nullable to support dual-mode tenant-wide events
 * (e.g. `EVENT_KB_DECISION_DEBT_THRESHOLD` with `user_id == null`).
 * Adapters that require a per-user destination (email To: address,
 * Discord user mention) should append a `status: 'skipped'` entry
 * with an explanatory `error` when `$user` is null rather than
 * throwing.
 */
interface NotificationChannelInterface
{
    /**
     * The string key used in `notification_preferences.channel` and
     * `channel_dispatch_log[*].channel` for this adapter. Mirrors
     * one of `NotificationPreference::CHANNEL_*` constants.
     */
    public function name(): string;

    /**
     * Append a delivery-log entry to the given notification event
     * row. The adapter performs its side-effect (queue a mail,
     * POST to a webhook, etc.) and then appends a single
     * `{channel, status, at, error?}` entry to the row's
     * `channel_dispatch_log`.
     */
    public function send(
        BaseNotificationEvent $event,
        ?User $user,
        NotificationEvent $eventRow,
    ): void;
}
