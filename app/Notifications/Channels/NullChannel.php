<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\NotificationEvent;
use App\Models\User;
use App\Notifications\Events\BaseNotificationEvent;
use Illuminate\Support\Carbon;

/**
 * v8.0/W1.2 — fallback adapter for any channel name that has no
 * concrete implementation registered yet.
 *
 * Behaviour: appends a `{status: 'skipped', error: 'no adapter
 * registered'}` entry to the row's `channel_dispatch_log` so the
 * miss is OBSERVABLE (R14 — failures must surface). The dispatcher
 * uses this for any channel that ChannelRegistry::for() doesn't
 * resolve to a real adapter, which in W1.2 is every channel
 * (in_app + email implementations land in W1.3; discord / slack /
 * teams / webhook in W2.1).
 *
 * Tests that need to assert "what channels were attempted" can
 * either register a `RecordingChannel` test double OR query the
 * persisted `channel_dispatch_log` after dispatch.
 */
final class NullChannel implements NotificationChannelInterface
{
    public function __construct(public readonly string $channelName)
    {
    }

    public function name(): string
    {
        return $this->channelName;
    }

    public function send(
        BaseNotificationEvent $event,
        ?User $user,
        NotificationEvent $eventRow,
    ): void {
        $log = $eventRow->channel_dispatch_log ?? [];
        $log[] = [
            'channel' => $this->channelName,
            'status' => 'skipped',
            'at' => Carbon::now()->toIso8601String(),
            'error' => 'no adapter registered for channel',
        ];
        $eventRow->channel_dispatch_log = $log;
        $eventRow->save();
    }
}
