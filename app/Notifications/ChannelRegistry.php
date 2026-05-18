<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\NotificationChannelInterface;
use App\Notifications\Channels\NullChannel;

/**
 * v8.0/W1.2 — runtime map of channel-name → adapter instance.
 *
 * Bound as a singleton in `NotificationServiceProvider::register()`.
 * `NotificationDispatcher` calls `for($channelName)` for every
 * enabled preference row and gets back either a concrete adapter
 * (when one is registered) or a `NullChannel` fallback that logs
 * `status: 'skipped'` for observability.
 *
 * W1.2 ships only the registry + the NullChannel fallback. Real
 * adapters register themselves later:
 *   - W1.3 — InAppChannel + EmailChannel
 *   - W2.1 — DiscordChannel + SlackChannel + TeamsChannel +
 *     WebhookChannel
 *
 * Tests swap real adapters for `RecordingChannel` test doubles via
 * `register()` at setUp time.
 */
final class ChannelRegistry
{
    /** @var array<string, NotificationChannelInterface> */
    private array $adapters = [];

    public function register(NotificationChannelInterface $adapter): void
    {
        $this->adapters[$adapter->name()] = $adapter;
    }

    public function for(string $channelName): NotificationChannelInterface
    {
        return $this->adapters[$channelName] ?? new NullChannel($channelName);
    }

    /**
     * @return array<string>
     */
    public function registered(): array
    {
        return array_keys($this->adapters);
    }
}
