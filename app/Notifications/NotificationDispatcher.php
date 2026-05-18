<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.0/W1.2 — listener that handles every `BaseNotificationEvent`
 * subclass (registered via `Event::listen` in
 * `NotificationServiceProvider`).
 *
 * Implements the 3-step protocol from ADR 0012 §Dispatcher per
 * recipient with these load-bearing invariants:
 *
 *   1. **Recipient dedup.** The publisher may pass overlapping
 *      `User` objects (e.g. when recipients come from union of
 *      multiple roles/groups). The dispatcher collapses by
 *      `user_id` (and treats `null` as a single tenant-wide
 *      sentinel) so one logical recipient = one row, NEVER more.
 *
 *   2. **Stale recipient skip + late-delete race catch.** A user can
 *      be force-deleted in the gap between event publication and
 *      listener execution (especially when the listener is queued in
 *      v8.x). `NotificationEvent::create()` would throw on the `users`
 *      FK and abort the whole batch. The dispatcher re-checks
 *      `User::whereKey($id)->exists()` BEFORE insert and skips
 *      missing recipients with a warning log entry. The `exists()`
 *      probe is racy on its own (a concurrent `forceDelete` can land
 *      between the probe and the insert), so the insert is also
 *      wrapped in a `QueryException` catch: on FK violation the
 *      dispatcher logs and skips that recipient instead of aborting
 *      the whole batch (`$live` siblings still get their rows).
 *
 *   3. **After-commit dispatch.** Channel adapters perform
 *      external side-effects (Mail::queue, HTTP POST). If the
 *      publisher fires the event inside a `DB::transaction` that
 *      later rolls back, naive synchronous dispatch would have
 *      ALREADY queued the email even though the row + the
 *      domain mutation rolled back. Every recipient is dispatched
 *      via `DB::afterCommit` so the row insert + channel sends
 *      only happen once the outer transaction commits. Outside a
 *      transaction `afterCommit` fires immediately.
 *
 *   4. **Failure-log ownership = ADAPTER unless throw.** Per
 *      `NotificationChannelInterface` contract: adapters append
 *      their own success/queued/skipped log entry. If an adapter
 *      throws instead of catching internally, the dispatcher
 *      appends a single fallback `failed` entry. Adapters MUST
 *      NOT both log a `failed` entry AND throw — that would
 *      double-log the same channel.
 *
 * Idempotency note: in-batch dedup is handled here; cross-batch
 * idempotency (queue retry, manual replay) requires a payload-
 * hash column + UNIQUE on `notification_events` and is parked
 * for v8.0/W1.5 (alongside the prune cron, where the audit
 * retention semantics also live).
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly ChannelRegistry $channels,
    ) {
    }

    public function handle(BaseNotificationEvent $event): void
    {
        foreach ($this->uniqueRecipients($event) as $recipient) {
            DB::afterCommit(function () use ($event, $recipient): void {
                $this->dispatchForRecipient($event, $recipient);
            });
        }
    }

    /**
     * @return array<int|string, User|null>
     */
    private function uniqueRecipients(BaseNotificationEvent $event): array
    {
        $unique = [];
        foreach ($event->recipients() as $recipient) {
            // Use the user id as the dedup key; the literal string
            // `__tenant_wide__` collapses repeated null recipients
            // into one tenant-wide row.
            $key = $recipient === null ? '__tenant_wide__' : (string) $recipient->id;
            $unique[$key] ??= $recipient;
        }

        return $unique;
    }

    private function dispatchForRecipient(
        BaseNotificationEvent $event,
        ?User $recipient,
    ): void {
        if ($recipient !== null && ! $this->recipientStillExists($recipient)) {
            Log::warning('notification dispatch: recipient no longer exists', [
                'event_type' => $event->eventType(),
                'tenant_id' => $event->tenantId(),
                'user_id' => $recipient->id,
            ]);

            return;
        }

        $channels = $this->resolveEnabledChannels($event, $recipient);
        if ($channels === []) {
            return;
        }

        try {
            $row = NotificationEvent::create([
                'tenant_id' => $event->tenantId(),
                'user_id' => $recipient?->id,
                'event_type' => $event->eventType(),
                'payload' => $event->payload(),
                'channel_dispatch_log' => [],
            ]);
        } catch (QueryException $e) {
            // Only swallow integrity-constraint violations (SQLSTATE
            // class 23 — covers FK and unique). Everything else
            // (connection drops, deadlocks, syntax) re-raises so the
            // queue retry policy and the operator's error pipeline
            // still see real infra failures instead of silent drops.
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            // Late-delete race: the recipient was force-deleted between
            // the recipientStillExists() probe above and this insert,
            // so the users FK fires. Skip and let sibling recipients
            // in the same batch continue — aborting would punish
            // every $live recipient for one $stale collision.
            Log::warning('notification dispatch: insert failed (integrity constraint, likely late recipient delete)', [
                'event_type' => $event->eventType(),
                'tenant_id' => $event->tenantId(),
                'user_id' => $recipient?->id,
                'sqlstate' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($channels as $channelName) {
            $this->invokeChannel($event, $recipient, $row, $channelName);
        }
    }

    private function recipientStillExists(User $recipient): bool
    {
        // R2 — include soft-deleted rows: this guard exists only to
        // catch FK violations on `notification_events.user_id`, and a
        // soft-deleted user still satisfies that FK (the row is still
        // physically present). Only force-deleted recipients (gone
        // from the table) need to be skipped here; whether to suppress
        // notifications to soft-deleted users is a publisher policy
        // call, not the dispatcher's.
        return User::query()->withTrashed()->whereKey($recipient->id)->exists();
    }

    /**
     * @return array<int, string>
     */
    private function resolveEnabledChannels(
        BaseNotificationEvent $event,
        ?User $recipient,
    ): array {
        if ($recipient === null) {
            return (array) config(
                'askmydocs.notifications.system_event_channels',
                [NotificationPreference::CHANNEL_IN_APP],
            );
        }

        // Deterministic order (insertion id ASC) so adapter
        // invocation is reproducible across DB engines. Important
        // for the mixed success/failure regression test that pins
        // the stale-row contract: if order were engine-dependent
        // the test might or might not trigger the bug case.
        return NotificationPreference::query()
            ->where('tenant_id', $event->tenantId())
            ->where('user_id', $recipient->id)
            ->where('event_type', $event->eventType())
            ->where('enabled', true)
            ->orderBy('id')
            ->pluck('channel')
            ->all();
    }

    private function invokeChannel(
        BaseNotificationEvent $event,
        ?User $recipient,
        NotificationEvent $row,
        string $channelName,
    ): void {
        $adapter = $this->channels->for($channelName);
        try {
            $adapter->send($event, $recipient, $row);
        } catch (Throwable $e) {
            $this->appendFailureLog($row, $channelName, $e->getMessage());
        }
    }

    private function appendFailureLog(
        NotificationEvent $row,
        string $channelName,
        string $error,
    ): void {
        // Mutate the same in-memory `$row` instance the dispatcher
        // hands to each subsequent adapter. The ADR 0012 contract
        // guarantees serialised invocations per row, so no concurrent
        // writer can clobber what we save here — but reloading via
        // `fresh()` and saving a *different* model object would leave
        // the dispatcher's `$row` stale, and the next adapter's own
        // `$row->save()` would overwrite this failure entry with the
        // pre-failure log. Keep the original `$row` as the single
        // mutable source of truth.
        $log = $row->channel_dispatch_log ?? [];
        $log[] = [
            'channel' => $channelName,
            'status' => 'failed',
            'at' => now()->toIso8601String(),
            'error' => $error,
        ];
        $row->channel_dispatch_log = $log;
        $row->save();
    }
}
