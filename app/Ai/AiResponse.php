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
        public array $toolCalls = [],
    ) {}

    /**
     * Immutable copy with a replaced answer body. Used by the v8.19 output
     * guardrail (App\Http\Controllers\Api\KbChatController) to swap the model's
     * raw answer for the sanitized one in a single place, so every downstream
     * consumer (sentinel check, confidence, cost, chat log, response) sees the
     * sanitized content without threading a second variable through the hot path.
     */
    public function withContent(string $content): self
    {
        return new self(
            $content,
            $this->provider,
            $this->model,
            $this->promptTokens,
            $this->completionTokens,
            $this->totalTokens,
            $this->finishReason,
            $this->toolCalls,
        );
    }
}

