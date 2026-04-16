<?php

namespace App\Ai;

final readonly class EmbeddingsResponse
{
    /**
     * @param  list<list<float>>  $embeddings  One embedding vector per input text, order-matched.
     */
    public function __construct(
        public array $embeddings,
        public string $provider,
        public string $model,
        public ?int $totalTokens = null,
    ) {}
}
