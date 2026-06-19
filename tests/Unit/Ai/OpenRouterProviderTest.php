<?php

namespace Tests\Unit\Ai;

use App\Ai\AiResponse;
use App\Ai\Providers\OpenRouterProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AskMyDocs OpenRouterProvider — HYBRID adapter (v8.16/W2).
 *
 * No-tools chat + embeddings flow through the laravel/ai SDK (native
 * `openrouter` driver — OpenAI-compatible /chat/completions + /embeddings),
 * metered by the finops hook. The MCP with-tools turn stays on the raw `Http::`
 * /chat/completions branch (the SDK cannot host AskMyDocs's external MCP tool
 * loop). The SDK call sets `usage: { include: true }` for real-cost capture and
 * sends the OpenRouter attribution headers. Both branches hit /chat/completions,
 * so these tests pin the request BODY (usage.include + tools) and headers.
 */
class OpenRouterProviderTest extends TestCase
{
    private function setupConfig(array $overrides = []): void
    {
        config()->set('ai.providers.openrouter', array_merge([
            'driver' => 'openrouter',
            'name' => 'openrouter',
            'key' => 'sk-or-test',
            'url' => 'https://openrouter.ai/api/v1',
            'http_referer' => 'https://kb.example.com',
            'x_title' => 'Enterprise KB',
            'timeout' => 30,
            'temperature' => 0.2,
            'max_tokens' => 1024,
            'models' => [
                'text' => ['default' => 'anthropic/claude-sonnet-4-20250514'],
                'embeddings' => ['default' => 'openai/text-embedding-3-small'],
            ],
        ], $overrides));
    }

    private function provider(): OpenRouterProvider
    {
        return new OpenRouterProvider(config('ai.providers.openrouter'));
    }

    public function test_name_and_embedding_support(): void
    {
        $this->setupConfig();
        $p = $this->provider();

        $this->assertSame('openrouter', $p->name());
        $this->assertTrue($p->supportsEmbeddings());
    }

    public function test_no_tools_chat_via_sdk_sets_usage_include_and_attribution_headers(): void
    {
        $this->setupConfig();
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'anthropic/claude-sonnet-4-20250514',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Hi there'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5, 'cost' => 0.00012],
            ], 200),
        ]);

        $res = $this->provider()->chat('sys', 'user');

        $this->assertInstanceOf(AiResponse::class, $res);
        $this->assertSame('Hi there', $res->content);
        $this->assertSame('openrouter', $res->provider);
        $this->assertSame(3, $res->promptTokens);
        $this->assertSame(2, $res->completionTokens);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return str_contains($req->url(), '/chat/completions')
                && $req->hasHeader('Authorization', 'Bearer sk-or-test')
                && $req->hasHeader('HTTP-Referer', 'https://kb.example.com')
                && $req->hasHeader('X-OpenRouter-Title', 'Enterprise KB')
                // usage.include=true → OpenRouter returns the real billed cost.
                && ($body['usage']['include'] ?? null) === true;
        });
    }

    public function test_with_tools_chat_uses_raw_http_with_legacy_title_header(): void
    {
        $this->setupConfig();
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'anthropic/claude-sonnet-4-20250514',
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_7',
                            'type' => 'function',
                            'function' => ['name' => 'kb_search', 'arguments' => '{"q":"y"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 4, 'total_tokens' => 13],
            ], 200),
        ]);

        $tools = [['type' => 'function', 'function' => ['name' => 'kb_search', 'parameters' => []]]];
        $res = $this->provider()->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'find y'],
        ], ['tools' => $tools, 'tool_choice' => 'auto']);

        $this->assertSame('tool_calls', $res->finishReason);
        $this->assertCount(1, $res->toolCalls);
        $this->assertSame('kb_search', $res->toolCalls[0]['name']);
        $this->assertSame('{"q":"y"}', $res->toolCalls[0]['arguments']);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return $req->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $req->hasHeader('Authorization', 'Bearer sk-or-test')
                && $req->hasHeader('HTTP-Referer', 'https://kb.example.com')
                && $req->hasHeader('X-Title', 'Enterprise KB')
                && isset($body['tools'])
                && $body['tool_choice'] === 'auto';
        });
    }

    public function test_mcp_final_turn_with_tool_history_and_no_tools_routes_to_http(): void
    {
        // The MCP loop's final answer turn (no `tools`, but tool-role history)
        // must route to raw Http:: /chat/completions, not the SDK path. Both
        // OpenRouter branches hit /chat/completions, so assert via the body:
        // tool history present, no `tools` key offered.
        $this->setupConfig();
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'anthropic/claude-sonnet-4-20250514',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'final answer'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 5, 'total_tokens' => 45],
            ], 200),
        ]);

        $res = $this->provider()->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'find x'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'kb', 'arguments' => '{}']]]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'name' => 'kb', 'content' => '{"r":1}'],
        ], []);

        $this->assertSame('final answer', $res->content);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return str_contains($req->url(), '/chat/completions')
                && ! array_key_exists('tools', $body)
                && $body['messages'][2]['role'] === 'assistant'
                && $body['messages'][3]['role'] === 'tool';
        });
    }

    public function test_generate_embeddings_via_sdk_returns_vectors(): void
    {
        $this->setupConfig(['models' => [
            'text' => ['default' => 'anthropic/claude-sonnet-4-20250514'],
            'embeddings' => ['default' => 'qwen/qwen3-embedding-4b'],
        ]]);
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'qwen/qwen3-embedding-4b',
                'data' => [
                    ['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                    ['index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
                ],
                'usage' => ['prompt_tokens' => 12],
            ], 200),
        ]);

        $res = $this->provider()->generateEmbeddings(['hello', 'world']);

        $this->assertSame('openrouter', $res->provider);
        $this->assertSame('qwen/qwen3-embedding-4b', $res->model);
        $this->assertSame([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], $res->embeddings);

        Http::assertSent(function (Request $req) {
            return str_contains($req->url(), '/embeddings')
                && $req->hasHeader('HTTP-Referer', 'https://kb.example.com')
                && $req['model'] === 'qwen/qwen3-embedding-4b'
                && $req['input'] === ['hello', 'world'];
        });
    }

    public function test_no_tools_chat_with_history_rejects_non_user_last_message(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chatWithHistory requires the last message to have role="user"; got role="assistant".');

        $this->provider()->chatWithHistory('s', [
            ['role' => 'user', 'content' => 'Hi.'],
            ['role' => 'assistant', 'content' => 'Hello.'],
        ]);
    }
}
