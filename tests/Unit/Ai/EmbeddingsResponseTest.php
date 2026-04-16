<?php

namespace Tests\Unit\Ai;

use App\Ai\EmbeddingsResponse;
use PHPUnit\Framework\TestCase;

class EmbeddingsResponseTest extends TestCase
{
    public function test_holds_ordered_embeddings(): void
    {
        $vectors = [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]];

        $response = new EmbeddingsResponse(
            embeddings: $vectors,
            provider: 'openai',
            model: 'text-embedding-3-small',
            totalTokens: 42,
        );

        $this->assertCount(2, $response->embeddings);
        $this->assertSame($vectors, $response->embeddings);
        $this->assertSame('openai', $response->provider);
        $this->assertSame('text-embedding-3-small', $response->model);
        $this->assertSame(42, $response->totalTokens);
    }

    public function test_allows_empty_embeddings_and_null_tokens(): void
    {
        $response = new EmbeddingsResponse(
            embeddings: [],
            provider: 'gemini',
            model: 'text-embedding-004',
        );

        $this->assertSame([], $response->embeddings);
        $this->assertNull($response->totalTokens);
    }

    public function test_is_immutable(): void
    {
        $reflection = new \ReflectionClass(EmbeddingsResponse::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
    }
}
