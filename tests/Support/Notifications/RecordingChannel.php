<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use App\Models\NotificationEvent;
use App\Models\User;
use App\Notifications\Channels\NotificationChannelInterface;
use App\Notifications\Events\BaseNotificationEvent;
use Illuminate\Support\Carbon;

/**
 * v8.0/W1.2 — test double for `NotificationChannelInterface`.
 *
 * Records every `send()` invocation in-memory (queryable via
 * `invocations()`) AND appends a `status: 'delivered'` entry to
 * the row's `channel_dispatch_log` so the persistence contract is
 * exercised end-to-end (the production adapters in W1.3 will
 * append `delivered` / `queued` / `failed` from their own
 * side-effects).
 */
final class RecordingChannel implements NotificationChannelInterface
{
    /** @var array<int, array{event_type:string,user_id:?int,event_row_id:int}> */
    private array $invocations = [];

    public function __construct(private readonly string $channelName)
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
        $this->invocations[] = [
            'event_type' => $event->eventType(),
            'user_id' => $user?->id,
            'event_row_id' => $eventRow->id,
        ];

        $log = $eventRow->channel_dispatch_log ?? [];
        $log[] = [
            'channel' => $this->channelName,
            'status' => 'delivered',
            'at' => Carbon::now()->toIso8601String(),
        ];
        $eventRow->channel_dispatch_log = $log;
        $eventRow->save();
    }

    /**
     * @return array<int, array{event_type:string,user_id:?int,event_row_id:int}>
     */
    public function invocations(): array
    {
        return $this->invocations;
    }
}
