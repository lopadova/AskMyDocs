<?php

namespace Tests\Unit\Ai;

use App\Ai\AiResponse;
use PHPUnit\Framework\TestCase;

class AiResponseTest extends TestCase
{
    public function test_constructs_with_required_fields(): void
    {
        $response = new AiResponse(
            content: 'Hello world',
            provider: 'openai',
            model: 'gpt-4o',
        );

        $this->assertSame('Hello world', $response->content);
        $this->assertSame('openai', $response->provider);
        $this->assertSame('gpt-4o', $response->model);
        $this->assertNull($response->promptTokens);
        $this->assertNull($response->completionTokens);
        $this->assertNull($response->totalTokens);
        $this->assertNull($response->finishReason);
    }

    public function test_stores_token_usage_and_finish_reason(): void
    {
        $response = new AiResponse(
            content: 'ok',
            provider: 'anthropic',
            model: 'claude-sonnet-4-20250514',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            finishReason: 'stop',
        );

        $this->assertSame(10, $response->promptTokens);
        $this->assertSame(5, $response->completionTokens);
        $this->assertSame(15, $response->totalTokens);
        $this->assertSame('stop', $response->finishReason);
    }

    public function test_is_immutable(): void
    {
        $response = new AiResponse(content: 'ok', provider: 'openai', model: 'gpt-4o');

        $reflection = new \ReflectionClass($response);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
    }
}
