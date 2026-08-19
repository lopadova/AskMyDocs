<?php

declare(strict_types=1);

namespace App\Ai\Tools;

final readonly class ChatToolInvocationResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $status,
        public mixed $payload,
        public array $metadata = [],
        public ?string $error = null,
    ) {}

    public function requiresInteraction(): bool
    {
        return in_array($this->status, ['confirmation_required', 'input_required', 'task_accepted'], true);
    }
}
