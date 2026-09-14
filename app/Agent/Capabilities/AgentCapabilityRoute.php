<?php

declare(strict_types=1);

namespace App\Agent\Capabilities;

final readonly class AgentCapabilityRoute
{
    /**
     * @param list<string> $candidateTools
     * @param list<string> $reasonCodes
     */
    public function __construct(
        public bool $liveDataRequired,
        public string $entity,
        public string $operation,
        public array $candidateTools,
        public array $reasonCodes,
        public int $latencyMs = 0,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
    ) {}
}
