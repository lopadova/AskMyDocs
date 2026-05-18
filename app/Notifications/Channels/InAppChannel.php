<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\NotificationEvent;
use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;
use Illuminate\Support\Carbon;

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
 * Per `NotificationChannelInterface` contract: the adapter mutates
 * the SAME `$eventRow` instance the dispatcher hands to each
 * subsequent adapter, and saves once. The dispatcher's per-row
 * serialisation (ADR 0012) guarantees no concurrent writer races
 * us on the same row.
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
        $log = $eventRow->channel_dispatch_log ?? [];
        $log[] = [
            'channel' => $this->name(),
            'status' => 'delivered',
            'at' => Carbon::now()->toIso8601String(),
        ];
        $eventRow->channel_dispatch_log = $log;
        $eventRow->save();
    }
}
