<?php

namespace Tests\Unit\Ai;

use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Ai\Providers\OpenAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AskMyDocs OpenAiProvider — HYBRID adapter (v8.16/W2).
 *
 * The no-tools chat turn + embeddings go through the laravel/ai SDK (native
 * `openai` driver: `/responses` + `/embeddings`), metered by the finops
 * lifecycle hook. The MCP **with-tools** turn stays on the raw `Http::`
 * `/chat/completions` branch (the SDK cannot host AskMyDocs's external MCP tool
 * loop — see W2-sdk-migration-findings.md). These tests pin BOTH branches: the
 * SDK path maps the SDK response onto `AiResponse`, and the Http path preserves
 * the dynamic-JSON-tools passthrough + `tool_choice` + `tool_calls`
 * normalisation. The SDK calls the OpenAI API through Illuminate's HTTP client,
 * so `Http::fake()` intercepts every branch on the same host.
 */
class OpenAiProviderTest extends TestCase
{
    private function setupConfig(array $overrides = []): void
    {
        config()->set('ai.providers.openai', array_merge([
            'driver' => 'openai',
            'name' => 'openai',
            'key' => 'sk-test',
            'url' => 'https://api.openai.com/v1',
            'timeout' => 30,
            'temperature' => 0.2,
            'max_tokens' => 1024,
            'models' => [
                'text' => ['default' => 'gpt-4o'],
                'embeddings' => ['default' => 'text-embedding-3-small'],
            ],
        ], $overrides));
    }

    private function provider(): OpenAiProvider
    {
        return new OpenAiProvider(config('ai.providers.openai'));
    }

    public function test_name_and_embedding_support(): void
    {
        $this->setupConfig();
        $p = $this->provider();

        $this->assertSame('openai', $p->name());
        $this->assertTrue($p->supportsEmbeddings());
    }

    public function test_no_tools_chat_via_sdk_returns_ai_response(): void
    {
        $this->setupConfig();
        // SDK no-tools chat hits the OpenAI `/responses` endpoint.
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_1',
                'model' => 'gpt-4o',
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'status' => 'completed',
                    'content' => [['type' => 'output_text', 'text' => 'Hello!']],
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
            ], 200),
        ]);

        $res = $this->provider()->chat('You are helpful.', 'Hi');

        $this->assertInstanceOf(AiResponse::class, $res);
        $this->assertSame('Hello!', $res->content);
        $this->assertSame('openai', $res->provider);
        $this->assertSame('gpt-4o', $res->model);
        $this->assertSame(10, $res->promptTokens);
        $this->assertSame(4, $res->completionTokens);
        $this->assertSame(14, $res->totalTokens);
        $this->assertSame([], $res->toolCalls);

        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/responses')
            && $req->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_with_tools_chat_uses_raw_http_chat_completions(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o',
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_42',
                            'type' => 'function',
                            'function' => ['name' => 'kb_search', 'arguments' => '{"q":"x"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 12, 'total_tokens' => 42],
            ], 200),
        ]);

        $tools = [['type' => 'function', 'function' => ['name' => 'kb_search', 'parameters' => []]]];
        $res = $this->provider()->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'find x'],
        ], ['tools' => $tools, 'tool_choice' => 'auto']);

        $this->assertSame('', $res->content);
        $this->assertSame('tool_calls', $res->finishReason);
        $this->assertCount(1, $res->toolCalls);
        $this->assertSame('kb_search', $res->toolCalls[0]['name']);
        $this->assertSame('call_42', $res->toolCalls[0]['id']);
        $this->assertSame('{"q":"x"}', $res->toolCalls[0]['arguments']);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return $req->url() === 'https://api.openai.com/v1/chat/completions'
                && $req->hasHeader('Authorization', 'Bearer sk-test')
                && $body['model'] === 'gpt-4o'
                && $body['messages'][0] === ['role' => 'system', 'content' => 'sys']
                && isset($body['tools'])
                && $body['tool_choice'] === 'auto';
        });
    }

    public function test_with_tools_chat_replays_assistant_and_tool_messages(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'done'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 3, 'total_tokens' => 53],
            ], 200),
        ]);

        $this->provider()->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'find x'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'kb_search', 'arguments' => '{}']]]],
            ['role' => 'tool', 'content' => 'result', 'tool_call_id' => 'call_1', 'name' => 'kb_search'],
        ], ['tools' => [['type' => 'function', 'function' => ['name' => 'kb_search']]]]);

        Http::assertSent(function (Request $req) {
            $msgs = $req->data()['messages'];
            return count($msgs) === 4
                && $msgs[2]['role'] === 'assistant'
                && isset($msgs[2]['tool_calls'])
                && $msgs[3]['role'] === 'tool'
                && $msgs[3]['tool_call_id'] === 'call_1'
                && $msgs[3]['name'] === 'kb_search';
        });
    }

    public function test_mcp_final_turn_with_tool_history_and_no_tools_routes_to_http(): void
    {
        // The MCP loop's FINAL answer turn (McpToolCallingService) is invoked
        // WITHOUT `tools` but with assistant `tool_calls` + `role:'tool'` history
        // the SDK can't represent. It must route to raw Http:: /chat/completions,
        // NOT the SDK /responses path (which would throw on the tool roles).
        $this->setupConfig();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4o',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'final answer'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 5, 'total_tokens' => 45],
            ], 200),
        ]);

        $res = $this->provider()->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'find x'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'kb', 'arguments' => '{}']]]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'name' => 'kb', 'content' => '{"r":1}'],
        ], []); // no `tools` in options — the loop's final turn

        $this->assertSame('final answer', $res->content);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return $req->url() === 'https://api.openai.com/v1/chat/completions'
                && ! array_key_exists('tools', $body) // no tools offered on the final turn
                && $body['messages'][2]['role'] === 'assistant'
                && $body['messages'][3]['role'] === 'tool';
        });
    }

    public function test_generate_embeddings_via_sdk_returns_vectors(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'text-embedding-3-small',
                'data' => [
                    ['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                    ['index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
                ],
                'usage' => ['prompt_tokens' => 12],
            ], 200),
        ]);

        $res = $this->provider()->generateEmbeddings(['first', 'second']);

        $this->assertInstanceOf(EmbeddingsResponse::class, $res);
        $this->assertSame([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], $res->embeddings);
        $this->assertSame('openai', $res->provider);
        $this->assertSame('text-embedding-3-small', $res->model);

        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/embeddings'));
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

    public function test_with_tools_chat_throws_on_http_error(): void
    {
        $this->setupConfig();
        Http::fake(['*' => Http::response(['error' => 'quota'], 429)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        $this->provider()->chatWithHistory('s', [
            ['role' => 'user', 'content' => 'u'],
        ], ['tools' => [['type' => 'function', 'function' => ['name' => 'x']]]]);
    }
}
