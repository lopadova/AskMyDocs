<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Mail\NotificationMail;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;
use App\Notifications\NotificationEventLogger;
use App\Notifications\Unsubscribe\UnsubscribeTokenSigner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * v8.0/W1.3 — email channel adapter.
 *
 * Queues `NotificationMail` to the recipient's address via the
 * configured Laravel mail driver. The mailable is marked
 * `ShouldQueue`, so the actual SMTP / API call happens on a queue
 * worker rather than blocking the dispatch thread — this is what
 * the channel's `'queued'` log status reflects (we record that the
 * mail was handed off to the queue, not that it was delivered to
 * the inbox).
 *
 * Tenant-wide events (`$user === null`, e.g. an aggregate
 * decision-debt threshold event) have no `To:` address; the channel
 * records `status: 'skipped'` with an explanatory error rather than
 * throwing — see `NotificationChannelInterface` contract.
 *
 * Failure ownership (per ADR 0012): the adapter catches its own
 * mailer-side failures (queue connection drops, bad addresses) and
 * appends `status: 'failed'` so the dispatcher's fallback failed-log
 * entry does not double-log this channel. The catch is narrow —
 * only `Throwable` from the `Mail::to()->queue()` call — so
 * configuration errors (missing HMAC secret, malformed payload)
 * still propagate up to the dispatcher's broader try/catch.
 *
 * Every log append routes through {@see NotificationEventLogger::append()}
 * so concurrent baseline + external-job writers on the same row
 * cannot lost-update each other under `QUEUE_CONNECTION=sync`.
 */
final class EmailChannel implements NotificationChannelInterface
{
    public function name(): string
    {
        return 'email';
    }

    public function send(
        BaseNotificationEvent $event,
        ?User $user,
        NotificationEvent $eventRow,
    ): void {
        if ($user === null) {
            $this->appendLog($eventRow, 'skipped', 'tenant-wide event has no email recipient');
            return;
        }

        $email = (string) ($user->email ?? '');
        if ($email === '') {
            $this->appendLog($eventRow, 'skipped', 'recipient has no email address');
            return;
        }

        // HMAC unsubscribe is mandatory — the signer throws if the
        // secret is unset rather than ship plaintext "?user_id=X"
        // links. The dispatcher's `try/catch` will record the
        // RuntimeException as a `failed` channel entry, which is
        // the correct behavior — the operator needs to see and
        // fix the misconfiguration.
        $unsubscribeUrl = $this->buildUnsubscribeUrl(
            $event->tenantId(),
            (int) $user->id,
            $event->eventType(),
        );

        try {
            Mail::to($email)->queue(new NotificationMail(
                tenantId: $event->tenantId(),
                eventType: $event->eventType(),
                payload: $event->payload(),
                eventRowId: (int) $eventRow->id,
                unsubscribeUrl: $unsubscribeUrl,
                userName: $user->name === null ? null : (string) $user->name,
            ));
        } catch (Throwable $e) {
            Log::warning('EmailChannel: queue dispatch failed', [
                'event_type' => $event->eventType(),
                'tenant_id' => $event->tenantId(),
                'user_id' => $user->id,
                'event_row_id' => $eventRow->id,
                'error' => $e->getMessage(),
            ]);
            $this->appendLog($eventRow, 'failed', $e->getMessage());
            return;
        }

        $this->appendLog($eventRow, 'queued');
    }

    private function buildUnsubscribeUrl(string $tenantId, int $userId, string $eventType): string
    {
        $token = UnsubscribeTokenSigner::sign($tenantId, $userId, $eventType);
        return url('/notifications/unsubscribe/'.$token);
    }

    private function appendLog(NotificationEvent $eventRow, string $status, ?string $error = null): void
    {
        NotificationEventLogger::append(
            eventRowId: (int) $eventRow->getKey(),
            tenantId: (string) $eventRow->tenant_id,
            channel: $this->name(),
            status: $status,
            error: $error,
            inMemoryRow: $eventRow,
        );
    }
}
