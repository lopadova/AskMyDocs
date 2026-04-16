<?php

namespace App\Ai;

final readonly class AiResponse
{
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $totalTokens = null,
        public ?string $finishReason = null,
    ) {}
}
