<?php

namespace App\Services\ChatLog;

use App\Services\ChatLog\Drivers\DatabaseChatLogDriver;
use InvalidArgumentException;

final class ChatLogManager
{
    private ?ChatLogDriverInterface $driver = null;

    /**
     * Log a chat interaction. No-op if logging is disabled.
     */
    public function log(ChatLogEntry $entry): void
    {
        if (! config('chat-log.enabled', false)) {
            return;
        }

        try {
            $this->resolveDriver()->store($entry);
        } catch (\Throwable $e) {
            // Chat logging must never break the user response.
            // Log the failure to the standard Laravel log and move on.
            logger()->error('ChatLog: failed to store entry', [
                'driver' => config('chat-log.driver', 'database'),
                'error' => $e->getMessage(),
                'session_id' => $entry->sessionId,
            ]);
        }
    }

    /**
     * Check whether chat logging is enabled.
     */
    public function enabled(): bool
    {
        return (bool) config('chat-log.enabled', false);
    }

    private function resolveDriver(): ChatLogDriverInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $name = config('chat-log.driver', 'database');

        $this->driver = match ($name) {
            'database' => app(DatabaseChatLogDriver::class),
            // Future drivers: bigquery, cloudwatch, etc.
            default => throw new InvalidArgumentException(
                "Chat log driver [{$name}] is not supported. Supported: database."
            ),
        };

        return $this->driver;
    }
}
