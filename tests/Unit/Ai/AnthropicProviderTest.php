<?php

namespace Tests\Unit\Ai;

use App\Ai\Providers\AnthropicProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'api_key' => 'sk-ant-test',
            'api_version' => '2023-06-01',
            'chat_model' => 'claude-sonnet-4-20250514',
            'temperature' => 0.2,
            'max_tokens' => 2048,
            'timeout' => 30,
        ], $overrides);
    }

    public function test_name_and_no_embedding_support(): void
    {
        $p = new AnthropicProvider($this->config());
        $this->assertSame('anthropic', $p->name());
        $this->assertFalse($p->supportsEmbeddings());
    }

    public function test_chat_sends_messages_without_system_in_messages_array(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello'],
                    ['type' => 'text', 'text' => ' world.'],
                ],
                'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider($this->config());
        $res = $p->chat('SYS', 'Hi');

        $this->assertSame('Hello world.', $res->content);
        $this->assertSame('anthropic', $res->provider);
        $this->assertSame('claude-sonnet-4-20250514', $res->model);
        $this->assertSame(20, $res->promptTokens);
        $this->assertSame(8, $res->completionTokens);
        $this->assertSame(28, $res->totalTokens);
        $this->assertSame('end_turn', $res->finishReason);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return $req->url() === 'https://api.anthropic.com/v1/messages'
                && $req->hasHeader('x-api-key', 'sk-ant-test')
                && $req->hasHeader('anthropic-version', '2023-06-01')
                && $body['system'] === 'SYS'
                && count($body['messages']) === 1
                && $body['messages'][0]['role'] === 'user';
        });
    }

    public function test_generate_embeddings_throws(): void
    {
        $p = new AnthropicProvider($this->config());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not provide an embeddings API/i');

        $p->generateEmbeddings(['any']);
    }

    public function test_ignores_non_text_content_blocks(): void
    {
        Http::fake([
            '*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [
                    ['type' => 'tool_use', 'id' => 'x'],
                    ['type' => 'text', 'text' => 'only this'],
                ],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200),
        ]);

        $p = new AnthropicProvider($this->config());
        $res = $p->chat('s', 'u');

        $this->assertSame('only this', $res->content);
    }
}
