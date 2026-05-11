<?php

declare(strict_types=1);

namespace App\Connectors;

use Carbon\Carbon;

/**
 * v4.5/W1 — Health probe result for a connector installation.
 *
 * Returned by {@see ConnectorInterface::health()} so the admin UI can
 * render a per-installation status pill without forcing a full sync.
 *
 * `state` values:
 *   - `healthy`  : last upstream ping succeeded; tokens are valid
 *   - `degraded` : upstream responded but flagged a soft issue (e.g.
 *                  rate-limit warning, partial scope missing)
 *   - `errored`  : upstream is unreachable or rejected the credentials
 *
 * Probe implementations should be fast (a single `about` / `me`
 * endpoint call typically) and MUST NOT mutate any state.
 */
final class HealthStatus
{
    public const STATE_HEALTHY = 'healthy';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_ERRORED = 'errored';

    public const STATES = [
        self::STATE_HEALTHY,
        self::STATE_DEGRADED,
        self::STATE_ERRORED,
    ];

    public function __construct(
        public readonly string $state,
        public readonly Carbon $lastCheck,
        public readonly ?string $message = null,
    ) {
        if (! in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException(
                "Invalid HealthStatus state '{$state}'. Expected one of: ".implode(', ', self::STATES)
            );
        }
    }

    public static function healthy(?string $message = null): self
    {
        return new self(self::STATE_HEALTHY, Carbon::now(), $message);
    }

    public static function degraded(string $message): self
    {
        return new self(self::STATE_DEGRADED, Carbon::now(), $message);
    }

    public static function errored(string $message): self
    {
        return new self(self::STATE_ERRORED, Carbon::now(), $message);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'last_check' => $this->lastCheck->toIso8601String(),
            'message' => $this->message,
        ];
    }
}
