<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\NotificationEvent;
use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;
use App\Notifications\NotificationEventLogger;

/**
 * v8.0/W1.3 — in-app channel adapter.
 *
 * The `notification_events` row created by `NotificationDispatcher`
 * IS the bell feed: the SPA reads from that table directly (W1.4
 * lands the React `NotificationBell` + `NotificationPanel` polling
 * `/api/notifications`). InAppChannel therefore has no external
 * side-effect — its job is solely to append the
 * `status: 'delivered'` entry to the row's `channel_dispatch_log`
 * so the audit trail correctly records the channel as delivered
 * even though no message left the system boundary.
 *
 * The append goes through {@see NotificationEventLogger::append()}
 * so writes from sibling channels (in-memory baseline + queued
 * external job under `QUEUE_CONNECTION=sync`) cannot lost-update
 * each other — the helper takes a `lockForUpdate` on the row,
 * reads the FRESH `channel_dispatch_log` from the DB, appends, and
 * refreshes the in-memory copy the dispatcher hands to the next
 * adapter.
 */
final class InAppChannel implements NotificationChannelInterface
{
    public function name(): string
    {
        return 'in_app';
    }

    public function send(
        BaseNotificationEvent $event,
        ?User $user,
        NotificationEvent $eventRow,
    ): void {
        NotificationEventLogger::append(
            eventRowId: (int) $eventRow->getKey(),
            tenantId: (string) $eventRow->tenant_id,
            channel: $this->name(),
            status: 'delivered',
            inMemoryRow: $eventRow,
        );
    }
}
