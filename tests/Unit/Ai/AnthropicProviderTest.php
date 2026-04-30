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

    public function test_chat_stream_via_fallback_emits_text_delta_then_finish(): void
    {
        // The fallback streaming path delegates to chatWithHistory and
        // re-emits the response as one text-delta + one finish chunk.
        // This is the W3.1 default for every provider; native HTTP-SSE
        // streaming is a follow-up enhancement that overrides
        // chatStream() per-provider without breaking this contract.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [
                    ['type' => 'text', 'text' => 'Streamed reply'],
                ],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider($this->config());
        $chunks = iterator_to_array($p->chatStream('SYS', [
            ['role' => 'user', 'content' => 'Hi'],
        ]), preserve_keys: false);

        $this->assertCount(2, $chunks, 'fallback streaming yields exactly text-delta + finish');
        $this->assertSame('text-delta', $chunks[0]->type);
        $this->assertSame('Streamed reply', $chunks[0]->payload['textDelta']);
        $this->assertSame('finish', $chunks[1]->type);
        $this->assertSame('end_turn', $chunks[1]->payload['finishReason']);
        $this->assertSame(12, $chunks[1]->payload['usage']['promptTokens']);
        $this->assertSame(7, $chunks[1]->payload['usage']['completionTokens']);
    }

    public function test_chat_stream_skips_text_delta_on_empty_response(): void
    {
        // Edge case: provider returns empty content (rare but possible
        // with strict tool-only responses). The fallback skips the
        // text-delta in that case so the FE doesn't render an empty
        // assistant bubble; only the finish event fires.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider($this->config());
        $chunks = iterator_to_array($p->chatStream('SYS', [
            ['role' => 'user', 'content' => 'Hi'],
        ]), preserve_keys: false);

        $this->assertCount(1, $chunks, 'no text-delta when response content is empty');
        $this->assertSame('finish', $chunks[0]->type);
    }
}
