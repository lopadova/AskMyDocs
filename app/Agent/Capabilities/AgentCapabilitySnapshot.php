<?php

declare(strict_types=1);

namespace App\Agent\Capabilities;

use JsonSerializable;

final readonly class AgentCapabilitySnapshot implements JsonSerializable
{
    /** @param array<string,AgentCapabilityDefinition> $capabilities */
    public function __construct(public array $capabilities, public string $hash, public int $bytes) {}

    public function get(string $tool): ?AgentCapabilityDefinition
    {
        return $this->capabilities[$tool] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function compact(): array
    {
        return array_values(array_map(
            static fn (AgentCapabilityDefinition $capability): array => $capability->compact(),
            $this->capabilities,
        ));
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return ['hash' => $this->hash, 'bytes' => $this->bytes, 'capabilities' => $this->capabilities];
    }
}
