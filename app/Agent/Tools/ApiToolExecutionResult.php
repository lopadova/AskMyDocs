<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use JsonSerializable;

/** Result of one logical API-tool action, which may use many HTTP requests. */
final readonly class ApiToolExecutionResult implements JsonSerializable
{
    /**
     * @param  array<string,mixed>  $body
     * @param  array<string,mixed>  $stats
     */
    public function __construct(
        public array $body,
        public int $physicalRequests,
        public bool $complete,
        public ?string $stopReason = null,
        public array $stats = [],
    ) {}

    public function successful(): bool
    {
        return ! isset($this->body['error']);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'body' => $this->body,
            'physical_requests' => $this->physicalRequests,
            'complete' => $this->complete,
            'stop_reason' => $this->stopReason,
            'stats' => $this->stats,
        ];
    }
}
