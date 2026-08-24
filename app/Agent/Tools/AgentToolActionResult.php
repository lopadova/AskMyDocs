<?php

declare(strict_types=1);

namespace App\Agent\Tools;

final readonly class AgentToolActionResult
{
    /** @param array<string,mixed> $body @param array<string,mixed> $stats */
    public function __construct(
        public array $body,
        public int $physicalRequests,
        public bool $complete = true,
        public ?string $stopReason = null,
        public array $stats = [],
    ) {}

    public function successful(): bool
    {
        return ! isset($this->body['error']);
    }

    public static function fromApi(ApiToolExecutionResult $result): self
    {
        return new self(
            $result->body,
            $result->physicalRequests,
            $result->complete,
            $result->stopReason,
            $result->stats,
        );
    }
}
