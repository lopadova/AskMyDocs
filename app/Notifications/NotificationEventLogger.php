<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.0/W2.1 — atomic append helper for
 * `notification_events.channel_dispatch_log`.
 *
 * The original W1 channel adapters (`InAppChannel` + `EmailChannel`)
 * mutated and saved the in-memory `NotificationEvent` instance the
 * dispatcher passed them, relying on the fact that all channels for
 * a given recipient ran sequentially on the same row instance. That
 * invariant breaks the moment W2.1 introduces a queueable job
 * ({@see \App\Jobs\SendExternalNotificationJob}) that loads its own
 * fresh row from the database — under `QUEUE_CONNECTION=sync` (the
 * default in `.env.example`), the job appends `delivered` to the DB
 * row WHILE the dispatcher's loop still holds a stale in-memory copy,
 * and the next adapter's `save()` overwrites the job's append.
 *
 * This helper makes every appender go through the same path:
 *   1. Open a transaction.
 *   2. `lockForUpdate()` the target row.
 *   3. Read the current `channel_dispatch_log` from the FRESH DB
 *      state (not from any caller's in-memory instance).
 *   4. Append the new entry + save.
 *   5. Also update the in-memory instance, if the caller passed one,
 *      so the dispatcher's next iteration starts from a non-stale
 *      view of the column (defence in depth — if a future caller
 *      decides to read from in-memory before the next helper call,
 *      it gets the right answer).
 *
 * R21 — read + write happen in the same transaction closure, so two
 * concurrent appenders never lose each other's entries. SQLite tests
 * don't enforce row-level locking but the call shape stays the same
 * — production Postgres serialises the append.
 *
 * Failure mode: any throw inside the transaction is caught and
 * logged at `error` level (the audit trail is best-effort; an
 * unwritable `channel_dispatch_log` must not break the underlying
 * notification dispatch path).
 */
final class NotificationEventLogger
{
    /**
     * Append a `{channel, status, at, error?}` entry to the given
     * row's `channel_dispatch_log` atomically. If `$inMemoryRow` is
     * non-null and references the same row, it is refreshed in place
     * so subsequent in-memory reads see the latest log.
     */
    public static function append(
        int $eventRowId,
        string $channel,
        string $status,
        ?string $error = null,
        ?NotificationEvent $inMemoryRow = null,
    ): void {
        try {
            DB::transaction(function () use ($eventRowId, $channel, $status, $error, $inMemoryRow): void {
                /** @var NotificationEvent|null $row */
                $row = NotificationEvent::query()
                    ->where('id', $eventRowId)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    Log::warning(
                        'NotificationEventLogger: target row missing',
                        ['event_row_id' => $eventRowId, 'channel' => $channel, 'status' => $status],
                    );
                    return;
                }

                $log = $row->channel_dispatch_log ?? [];
                $entry = [
                    'channel' => $channel,
                    'status' => $status,
                    'at' => Carbon::now()->toIso8601String(),
                ];
                if ($error !== null) {
                    $entry['error'] = $error;
                }
                $log[] = $entry;
                $row->channel_dispatch_log = $log;
                $row->save();

                // Sync the in-memory instance the caller may still
                // be reading from — protects against stale-state
                // bugs in callers that mix helper writes with
                // direct property reads.
                if ($inMemoryRow !== null && (int) $inMemoryRow->getKey() === $eventRowId) {
                    $inMemoryRow->channel_dispatch_log = $log;
                    $inMemoryRow->syncOriginalAttribute('channel_dispatch_log');
                }
            });
        } catch (Throwable $e) {
            Log::error(
                'NotificationEventLogger: failed to append channel_dispatch_log',
                [
                    'event_row_id' => $eventRowId,
                    'channel' => $channel,
                    'status' => $status,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }
}
