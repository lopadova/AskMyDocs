<?php

namespace App\Services\ChatLog;

final readonly class ChatLogEntry
{
    /**
     * @param  list<string>  $sources   Source file paths that contributed context.
     * @param  array<string, mixed>  $extra  Arbitrary metadata for debugging / analytics.
     */
    public function __construct(
        public string $sessionId,
        public ?int $userId,
        public string $question,
        public string $answer,
        public ?string $projectKey,
        public string $aiProvider,
        public string $aiModel,
        public int $chunksCount,
        public array $sources,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public ?int $totalTokens,
        public int $latencyMs,
        public ?string $clientIp,
        public ?string $userAgent,
        public array $extra = [],
    ) {}
}
