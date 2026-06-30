<?php

namespace Tests\Unit\Ai;

use App\Ai\AiResponse;
use App\Ai\Providers\AnthropicProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AskMyDocs AnthropicProvider — HYBRID adapter (v8.16/W2 SDK no-tools chat +
 * a translated raw-Http `/messages` with-tools chat path added in the
 * provider-extension step).
 *
 * Wire-level no-tools behaviour (request shape, retry, error mapping) is owned
 * by the SDK's Anthropic gateway. The MCP with-tools turn stays on raw `Http::`
 * `/messages`: the SDK cannot host AskMyDocs's external MCP tool loop. These
 * tests pin BOTH branches — the SDK path maps the SDK response onto `AiResponse`,
 * and the Http path translates the OpenAI-shaped tools + history into the
 * Anthropic Messages API (`input_schema`, `tool_use` / `tool_result` blocks,
 * system as a top-level field) and maps `tool_use` blocks back to the normalized
 * `{id,name,arguments}` shape. The SDK calls the Anthropic API through
 * Illuminate's HTTP client, so `Http::fake()` intercepts every branch.
 */
class AnthropicProviderTest extends TestCase
{
    private function setupConfig(array $overrides = []): void
    {
        config()->set('ai.providers.anthropic', array_merge([
            'driver' => 'anthropic',
            'name' => 'anthropic',
            'key' => 'sk-ant-test',
            'url' => 'https://api.anthropic.com/v1',
            'api_version' => '2023-06-01',
            'timeout' => 30,
            'temperature' => 0.2,
            'max_tokens' => 2048,
            'models' => [
                'text' => ['default' => 'claude-sonnet-4-20250514'],
            ],
        ], $overrides));
    }

    private function provider(): AnthropicProvider
    {
        return new AnthropicProvider(config('ai.providers.anthropic'));
    }

    public function test_name_and_no_embedding_support(): void
    {
        $this->setupConfig();
        $p = new AnthropicProvider(config('ai.providers.anthropic'));

        $this->assertSame('anthropic', $p->name());
        $this->assertFalse($p->supportsEmbeddings());
    }

    public function test_chat_returns_ai_response_with_text_and_metadata(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-20250514',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello'],
                    ['type' => 'text', 'text' => ' world.'],
                ],
                'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider(config('ai.providers.anthropic'));
        $res = $p->chat('You are helpful.', 'Hi');

        $this->assertInstanceOf(AiResponse::class, $res);
        $this->assertSame('Hello world.', $res->content);
        $this->assertSame('anthropic', $res->provider);
        $this->assertSame('claude-sonnet-4-20250514', $res->model);
        $this->assertSame(20, $res->promptTokens);
        $this->assertSame(8, $res->completionTokens);
        $this->assertSame(28, $res->totalTokens);
    }

    public function test_generate_embeddings_throws(): void
    {
        $this->setupConfig();
        $p = new AnthropicProvider(config('ai.providers.anthropic'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not provide an embeddings API/i');

        $p->generateEmbeddings(['any']);
    }

    public function test_chat_with_history_maps_multi_turn_response(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider(config('ai.providers.anthropic'));
        $res = $p->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ]);

        $this->assertSame('ok', $res->content);
        $this->assertSame(5, $res->promptTokens);
        $this->assertSame(2, $res->completionTokens);
    }

    public function test_chat_with_history_rejects_empty_message_list(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);

        (new AnthropicProvider(config('ai.providers.anthropic')))->chatWithHistory('s', []);
    }

    public function test_chat_with_history_rejects_non_user_last_message(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chatWithHistory requires the last message to have role="user"; got role="assistant".');

        (new AnthropicProvider(config('ai.providers.anthropic')))->chatWithHistory('s', [
            ['role' => 'user', 'content' => 'Hi.'],
            ['role' => 'assistant', 'content' => 'Hello.'],
        ]);
    }

    public function test_chat_with_history_allows_empty_assistant_content(): void
    {
        // A provider can return an empty assistant turn (the empty-content edge
        // case); AskMyDocs persists it and replays it in a later turn's history.
        // The SDK history mapping must accept an empty ASSISTANT message (only the
        // final user prompt must be non-empty), not throw. Copilot R3 regression.
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => 'recovered']],
                'usage' => ['input_tokens' => 6, 'output_tokens' => 3],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $res = (new AnthropicProvider(config('ai.providers.anthropic')))->chatWithHistory('s', [
            ['role' => 'user', 'content' => 'first'],
            ['role' => 'assistant', 'content' => ''], // empty assistant turn — must not throw
            ['role' => 'user', 'content' => 'follow up'],
        ]);

        $this->assertSame('recovered', $res->content);
    }

    public function test_chat_with_history_rejects_empty_user_content_in_history(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('role="user" requires a non-empty string "content"');

        (new AnthropicProvider(config('ai.providers.anthropic')))->chatWithHistory('s', [
            ['role' => 'user', 'content' => ''], // empty USER in history — still a bug
            ['role' => 'user', 'content' => 'real question'],
        ]);
    }

    public function test_chat_with_history_rejects_unsupported_role(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported message role');

        (new AnthropicProvider(config('ai.providers.anthropic')))->chatWithHistory('s', [
            ['role' => 'system', 'content' => 'nope'],
            ['role' => 'user', 'content' => 'Hi'],
        ]);
    }

    public function test_chat_rejects_non_numeric_max_tokens(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tokens must be numeric');

        (new AnthropicProvider(config('ai.providers.anthropic')))->chat('s', 'u', ['max_tokens' => 'abc']);
    }

    public function test_chat_rejects_non_numeric_temperature(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('temperature must be numeric');

        (new AnthropicProvider(config('ai.providers.anthropic')))->chat('s', 'u', ['temperature' => 'hot']);
    }

    public function test_chat_stream_via_fallback_emits_text_envelope_then_finish(): void
    {
        // chatStream() delegates to FallbackStreaming::streamFromChat() → the new
        // SDK chatViaSdk() path → re-emits the response as one SDK v6 text
        // envelope (text-start, text-delta, text-end) + one finish chunk. This
        // pins that the SDK migration did NOT break the streaming envelope shape.
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => 'Streamed reply']],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider(config('ai.providers.anthropic'));
        $chunks = iterator_to_array($p->chatStream('SYS', [
            ['role' => 'user', 'content' => 'Hi'],
        ]), preserve_keys: false);

        $this->assertCount(4, $chunks, 'fallback streaming yields text-start + text-delta + text-end + finish');
        $this->assertSame('text-start', $chunks[0]->type);
        $this->assertSame('text-delta', $chunks[1]->type);
        $this->assertSame('text-end', $chunks[2]->type);
        $this->assertSame('finish', $chunks[3]->type);

        // Text envelope MUST share one id end-to-end so SDK v6 stitches the
        // deltas into one rendered text part.
        $textId = $chunks[0]->payload['id'];
        $this->assertSame($textId, $chunks[1]->payload['id']);
        $this->assertSame($textId, $chunks[2]->payload['id']);

        // SDK v6 shape: text-delta carries `delta` (NOT `textDelta`).
        $this->assertSame('Streamed reply', $chunks[1]->payload['delta']);

        // Anthropic `end_turn` normalizes to the SDK union `'stop'`.
        $this->assertSame('stop', $chunks[3]->payload['finishReason']);
        $this->assertSame(12, $chunks[3]->payload['usage']['promptTokens']);
        $this->assertSame(7, $chunks[3]->payload['usage']['completionTokens']);
    }

    // ---------------------------------------------------------------------
    // HYBRID with-tools path — raw Http:: /messages (Anthropic Messages API).
    // ---------------------------------------------------------------------

    public function test_with_tools_chat_uses_raw_http_messages_and_normalizes_tool_calls(): void
    {
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_tool',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-20250514',
                'content' => [
                    ['type' => 'text', 'text' => 'Let me look that up.'],
                    ['type' => 'tool_use', 'id' => 'toolu_42', 'name' => 'kb_search', 'input' => ['q' => 'x']],
                ],
                'usage' => ['input_tokens' => 30, 'output_tokens' => 12],
                'stop_reason' => 'tool_use',
            ], 200),
        ]);

        $tools = [['type' => 'function', 'function' => ['name' => 'kb_search', 'description' => 'Search KB', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]]]];
        $res = $this->provider()->chatWithHistory('You are helpful.', [
            ['role' => 'user', 'content' => 'find x'],
        ], ['tools' => $tools, 'tool_choice' => 'auto']);

        // tool_use → normalized {id,name,arguments(JSON string)}.
        $this->assertSame('Let me look that up.', $res->content);
        $this->assertSame('tool_use', $res->finishReason);
        $this->assertSame(30, $res->promptTokens);
        $this->assertSame(12, $res->completionTokens);
        $this->assertSame(42, $res->totalTokens);
        $this->assertCount(1, $res->toolCalls);
        $this->assertSame('toolu_42', $res->toolCalls[0]['id']);
        $this->assertSame('kb_search', $res->toolCalls[0]['name']);
        $this->assertSame('{"q":"x"}', $res->toolCalls[0]['arguments']);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return $req->url() === 'https://api.anthropic.com/v1/messages'
                && $req->hasHeader('x-api-key', 'sk-ant-test')
                && $req->hasHeader('anthropic-version', '2023-06-01')
                // system is a TOP-LEVEL field, not a message.
                && ($body['system'] ?? null) === 'You are helpful.'
                && $body['messages'][0]['role'] === 'user'
                && $body['messages'][0]['content'][0]['type'] === 'text'
                && $body['messages'][0]['content'][0]['text'] === 'find x'
                // tools translated to {name, description, input_schema}.
                && $body['tools'][0]['name'] === 'kb_search'
                && $body['tools'][0]['description'] === 'Search KB'
                && ($body['tools'][0]['input_schema']['type'] ?? null) === 'object'
                && ! array_key_exists('parameters', $body['tools'][0])
                // tool_choice 'auto' → {type:'auto'}.
                && $body['tool_choice'] === ['type' => 'auto'];
        });
    }

    public function test_with_tools_chat_translates_assistant_tool_use_and_tool_result_replay(): void
    {
        // The MCP loop replays an assistant tool_calls turn + a role:'tool' result.
        // The provider must translate those into Anthropic tool_use / tool_result
        // content blocks. No `tools` here (final answer turn) → still routes to Http
        // because the history carries a tool turn.
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => 'done']],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 3],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $res = $this->provider()->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'find x'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'kb_search', 'arguments' => '{"q":"y"}']]]],
            ['role' => 'tool', 'content' => '{"hits":2}', 'tool_call_id' => 'toolu_1', 'name' => 'kb_search'],
        ], []);

        $this->assertSame('done', $res->content);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            $msgs = $body['messages'];

            // [0] user text, [1] assistant tool_use, [2] user tool_result.
            return $req->url() === 'https://api.anthropic.com/v1/messages'
                && ! array_key_exists('tools', $body) // no tools offered on the final turn
                && count($msgs) === 3
                && $msgs[1]['role'] === 'assistant'
                // empty assistant text is dropped — only the tool_use block remains.
                && count($msgs[1]['content']) === 1
                && $msgs[1]['content'][0]['type'] === 'tool_use'
                && $msgs[1]['content'][0]['id'] === 'toolu_1'
                && $msgs[1]['content'][0]['name'] === 'kb_search'
                && $msgs[1]['content'][0]['input'] === ['q' => 'y']
                && $msgs[2]['role'] === 'user'
                && $msgs[2]['content'][0]['type'] === 'tool_result'
                && $msgs[2]['content'][0]['tool_use_id'] === 'toolu_1'
                && $msgs[2]['content'][0]['content'] === '{"hits":2}';
        });
    }

    public function test_mcp_final_turn_with_tool_history_and_no_tools_routes_to_http_not_sdk(): void
    {
        // No `tools` in options, but assistant tool_calls + role:'tool' history
        // the SDK can't represent → must route to raw Http:: /messages. The SDK
        // path would throw on the 'tool' role via mapHistoryToSdkMessages().
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => 'final answer']],
                'usage' => ['input_tokens' => 40, 'output_tokens' => 5],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $res = $this->provider()->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'find x'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'kb', 'arguments' => '{}']]]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'name' => 'kb', 'content' => '{"r":1}'],
        ], []);

        $this->assertSame('final answer', $res->content);
        Http::assertSent(fn (Request $req) => $req->url() === 'https://api.anthropic.com/v1/messages');
    }

    public function test_with_tools_chat_throws_on_http_error(): void
    {
        $this->setupConfig();
        Http::fake(['*' => Http::response(['error' => 'overloaded'], 529)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        $this->provider()->chatWithHistory('s', [
            ['role' => 'user', 'content' => 'u'],
        ], ['tools' => [['type' => 'function', 'function' => ['name' => 'x']]]]);
    }

    public function test_no_tools_chat_still_routes_through_the_sdk_path(): void
    {
        // Regression guard: adding the with-tools routing must NOT divert a plain
        // no-tools/no-tool-history turn. Both branches hit /v1/messages with a
        // near-identical body, so prove SDK routing structurally: the with-tools
        // branch NEVER sends `tools` / `tool_choice` and NEVER emits tool content
        // blocks on a no-tools turn — and the SDK response still maps cleanly onto
        // AiResponse. (The explicit with-tools tests above pin the Http branch.)
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [['type' => 'text', 'text' => 'plain']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $res = $this->provider()->chat('sys', 'hi');

        $this->assertSame('plain', $res->content);
        $this->assertSame(5, $res->promptTokens);
        $this->assertSame(2, $res->completionTokens);
        $this->assertSame([], $res->toolCalls);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function test_chat_stream_with_empty_content_emits_only_finish_chunk(): void
    {
        // Edge case (regression guard re-added after the SDK migration, Copilot R2):
        // FallbackStreaming::streamFromChat() skips the whole text envelope when the
        // provider returns empty content (`if ($response->content !== '')`), so an
        // empty Anthropic response must yield ONLY a single `finish` chunk — never an
        // empty `text-start`/`text-delta`/`text-end` that renders as a blank bubble.
        $this->setupConfig();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-20250514',
                'content' => [], // no text blocks → SDK text === ''
                'usage' => ['input_tokens' => 4, 'output_tokens' => 0],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $p = new AnthropicProvider(config('ai.providers.anthropic'));
        $chunks = iterator_to_array($p->chatStream('SYS', [
            ['role' => 'user', 'content' => 'Hi'],
        ]), preserve_keys: false);

        $this->assertCount(1, $chunks, 'empty content yields only the finish chunk (no text envelope)');
        $this->assertSame('finish', $chunks[0]->type);
        $this->assertSame('stop', $chunks[0]->payload['finishReason']);
    }
}
