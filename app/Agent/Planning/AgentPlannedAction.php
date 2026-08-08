<?php

declare(strict_types=1);

namespace App\Agent\Planning;

use JsonSerializable;

final readonly class AgentPlannedAction implements JsonSerializable
{
    /** @param array<string,mixed> $arguments @param list<string> $dependsOn */
    public function __construct(
        public string $id,
        public string $tool,
        public array $arguments,
        public array $dependsOn,
        public string $purpose,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'depends_on' => $this->dependsOn,
            'purpose' => $this->purpose,
        ];
    }
}
