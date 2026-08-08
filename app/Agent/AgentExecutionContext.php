<?php

declare(strict_types=1);

namespace App\Agent;

use App\Support\SupportedLocale;
use Illuminate\Support\Facades\App;
use JsonSerializable;

/**
 * Immutable ownership and localization boundary for one agent execution.
 *
 * The full BCP-47 locale is preserved for connector requests and response
 * contracts; Laravel receives the matching catalog locale (currently en/it).
 */
final readonly class AgentExecutionContext implements JsonSerializable
{
    public function __construct(
        public string $runId,
        public string $tenantId,
        public ?string $projectKey,
        public string $channel,
        public string $actorType,
        public ?string $actorId,
        public string $locale,
        public string $timezone,
    ) {
        if ($runId === '' || $tenantId === '') {
            throw new \InvalidArgumentException('Agent execution context requires run and tenant identifiers.');
        }

        if (! in_array($channel, ['chat', 'widget'], true)) {
            throw new \InvalidArgumentException("Unsupported agent channel [{$channel}].");
        }

        if (! in_array($actorType, ['user', 'widget_identity', 'anonymous_widget'], true)) {
            throw new \InvalidArgumentException("Unsupported agent actor type [{$actorType}].");
        }

        if (! SupportedLocale::isSupported($locale)) {
            throw new \InvalidArgumentException("Unsupported agent locale [{$locale}].");
        }

        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Unsupported agent timezone [{$timezone}].");
        }
    }

    /** Restore request-local framework state at every job/continuation entry. */
    public function activate(): void
    {
        App::setLocale(SupportedLocale::catalog($this->locale));
        date_default_timezone_set($this->timezone);
    }

    /** @return array<string,string|null> */
    public function jsonSerialize(): array
    {
        return [
            'run_id' => $this->runId,
            'tenant_id' => $this->tenantId,
            'project_key' => $this->projectKey,
            'channel' => $this->channel,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) ($payload['run_id'] ?? ''),
            tenantId: (string) ($payload['tenant_id'] ?? ''),
            projectKey: isset($payload['project_key']) && $payload['project_key'] !== ''
                ? (string) $payload['project_key']
                : null,
            channel: (string) ($payload['channel'] ?? ''),
            actorType: (string) ($payload['actor_type'] ?? ''),
            actorId: isset($payload['actor_id']) ? (string) $payload['actor_id'] : null,
            locale: SupportedLocale::normalize(isset($payload['locale']) ? (string) $payload['locale'] : null),
            timezone: (string) ($payload['timezone'] ?? config('app.timezone', 'UTC')),
        );
    }
}
