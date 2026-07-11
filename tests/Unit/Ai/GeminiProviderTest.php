<?php

namespace Tests\Unit\Ai;

use App\Ai\AiResponse;
use App\Ai\Providers\GeminiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AskMyDocs GeminiProvider — HYBRID adapter (v8.16/W2 SDK no-tools chat +
 * embeddings, plus a translated raw-Http `generateContent` with-tools chat path
 * added in the provider-extension step).
 *
 * Wire-level no-tools behaviour (the assistant→model role remap, the
 * x-goog-api-key header auth, generateContent / batchEmbedContents) is owned by
 * the SDK's Gemini gateway. The MCP with-tools turn stays on raw `Http::`
 * `…:generateContent`: the SDK cannot host AskMyDocs's external MCP tool loop.
 * These tests pin BOTH branches — the SDK path maps the SDK responses onto the
 * AskMyDocs DTOs, and the Http path translates the OpenAI-shaped tools + history
 * into the Gemini `generateContent` shape (`functionDeclarations`, `functionCall`
 * / `functionResponse` parts, `system_instruction`) and maps `functionCall` parts
 * back to the normalized `{id,name,arguments}` shape. The R-logging-security
 * invariant (key in HEADER, never the URL) is asserted on BOTH branches.
 * `Http::fake()` intercepts every branch on the Google API endpoints.
 */
class GeminiProviderTest extends TestCase
{
    private function setupConfig(array $overrides = []): void
    {
        config()->set('ai.providers.gemini', array_merge([
            'driver' => 'gemini',
            'name' => 'gemini',
            'key' => 'AIzaTest',
            'url' => 'https://generativelanguage.googleapis.com/v1beta/',
            'timeout' => 30,
            'temperature' => 0.3,
            'max_tokens' => 512,
            'models' => [
                'text' => ['default' => 'gemini-2.0-flash'],
                'embeddings' => ['default' => 'text-embedding-004'],
            ],
        ], $overrides));
    }

    private function provider(): GeminiProvider
    {
        return new GeminiProvider(config('ai.providers.gemini'));
    }

    public function test_name_and_embedding_support(): void
    {
        $this->setupConfig();
        $p = new GeminiProvider(config('ai.providers.gemini'));

        $this->assertSame('gemini', $p->name());
        $this->assertTrue($p->supportsEmbeddings());
    }

    public function test_chat_returns_ai_response_with_text_and_metadata(): void
    {
        $this->setupConfig();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Ciao!']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 11,
                    'candidatesTokenCount' => 3,
                    'totalTokenCount' => 14,
                ],
                'modelVersion' => 'gemini-2.0-flash',
            ], 200),
        ]);

        $p = new GeminiProvider(config('ai.providers.gemini'));
        $res = $p->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ]);

        $this->assertInstanceOf(AiResponse::class, $res);
        $this->assertSame('Ciao!', $res->content);
        $this->assertSame('gemini', $res->provider);
        $this->assertSame(11, $res->promptTokens);
        $this->assertSame(3, $res->completionTokens);
        $this->assertSame(14, $res->totalTokens);

        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'models/gemini-2.0-flash:generateContent'));
    }

    public function test_api_key_is_sent_as_header_not_url_query_string(): void
    {
        // R-logging-security regression guard — query-string secrets leak into
        // access / proxy logs + APM traces. The SDK gemini gateway authenticates
        // via the x-goog-api-key HEADER (CreatesGeminiClient); pin that here so a
        // future SDK bump can't silently reintroduce the URL-key leak.
        $this->setupConfig();
        Http::fake([
            '*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'x']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
                'embeddings' => [['values' => [0.1]]],
            ], 200),
        ]);

        $p = new GeminiProvider(config('ai.providers.gemini'));
        $p->chat('sys', 'q');
        $p->generateEmbeddings(['one']);

        // Assert PER-ENDPOINT (Copilot R6): a single assertSent matches ANY one
        // request, so it would pass even if exactly one of the two leaked the key
        // into the URL. Pin BOTH the generateContent (chat) and batchEmbedContents
        // (embeddings) calls individually — a leak in either fails its own check.
        Http::assertSent(fn (Request $req) => str_contains($req->url(), ':generateContent')
            && $req->hasHeader('x-goog-api-key', 'AIzaTest')
            && ! str_contains($req->url(), 'AIzaTest')
            && ! str_contains($req->url(), 'key='));
        Http::assertSent(fn (Request $req) => str_contains($req->url(), ':batchEmbedContents')
            && $req->hasHeader('x-goog-api-key', 'AIzaTest')
            && ! str_contains($req->url(), 'AIzaTest')
            && ! str_contains($req->url(), 'key='));
    }

    public function test_generate_embeddings_returns_vectors(): void
    {
        $this->setupConfig();
        Http::fake([
            '*' => Http::response([
                'embeddings' => [
                    ['values' => [0.1, 0.2]],
                    ['values' => [0.3, 0.4]],
                ],
            ], 200),
        ]);

        $p = new GeminiProvider(config('ai.providers.gemini'));
        $res = $p->generateEmbeddings(['one', 'two']);

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $res->embeddings);
        $this->assertSame('gemini', $res->provider);

        Http::assertSent(fn (Request $req) => str_contains($req->url(), ':batchEmbedContents'));
    }

    public function test_chat_with_history_rejects_non_user_last_message(): void
    {
        $this->setupConfig();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chatWithHistory requires the last message to have role="user"; got role="assistant".');

        (new GeminiProvider(config('ai.providers.gemini')))->chatWithHistory('s', [
            ['role' => 'user', 'content' => 'Hi.'],
            ['role' => 'assistant', 'content' => 'Hello.'],
        ]);
    }

    // ---------------------------------------------------------------------
    // HYBRID with-tools path — raw Http:: generateContent.
    // ---------------------------------------------------------------------

    public function test_with_tools_chat_uses_raw_http_generate_content_and_normalizes_function_calls(): void
    {
        $this->setupConfig();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [
                        ['text' => 'Looking that up.'],
                        ['functionCall' => ['name' => 'kb_search', 'args' => ['q' => 'x']]],
                    ]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 18, 'candidatesTokenCount' => 9, 'totalTokenCount' => 27],
                'modelVersion' => 'gemini-2.0-flash',
            ], 200),
        ]);

        $tools = [['type' => 'function', 'function' => ['name' => 'kb_search', 'description' => 'Search KB', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]]]];
        $res = $this->provider()->chatWithHistory('You are helpful.', [
            ['role' => 'user', 'content' => 'find x'],
        ], ['tools' => $tools, 'tool_choice' => 'auto']);

        // functionCall part → normalized {id,name,arguments(JSON string)}.
        $this->assertSame('Looking that up.', $res->content);
        $this->assertSame('STOP', $res->finishReason);
        $this->assertSame(18, $res->promptTokens);
        $this->assertSame(9, $res->completionTokens);
        $this->assertSame(27, $res->totalTokens);
        $this->assertCount(1, $res->toolCalls);
        // Gemini returns no id — the provider synthesizes one ('call_…').
        $this->assertStringStartsWith('call_', $res->toolCalls[0]['id']);
        $this->assertSame('kb_search', $res->toolCalls[0]['name']);
        $this->assertSame('{"q":"x"}', $res->toolCalls[0]['arguments']);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return str_contains($req->url(), 'models/gemini-2.0-flash:generateContent')
                // R-logging-security: key in HEADER, never the URL.
                && $req->hasHeader('x-goog-api-key', 'AIzaTest')
                && ! str_contains($req->url(), 'AIzaTest')
                && ! str_contains($req->url(), 'key=')
                // system → system_instruction (Gemini has no 'system' role).
                && ($body['system_instruction']['parts'][0]['text'] ?? null) === 'You are helpful.'
                && ($body['contents'][0]['role'] ?? null) === 'user'
                && ($body['contents'][0]['parts'][0]['text'] ?? null) === 'find x'
                // tools → functionDeclarations.
                && ($body['tools'][0]['functionDeclarations'][0]['name'] ?? null) === 'kb_search'
                && ($body['tools'][0]['functionDeclarations'][0]['description'] ?? null) === 'Search KB'
                // tool_choice 'auto' → function_calling_config.mode AUTO.
                && ($body['tool_config']['function_calling_config']['mode'] ?? null) === 'AUTO';
        });
    }

    public function test_with_tools_chat_translates_model_function_call_and_function_response_replay(): void
    {
        // The MCP loop replays an assistant tool_calls turn + a role:'tool' result.
        // The provider must translate those into Gemini functionCall (model) /
        // functionResponse (user) parts. No `tools` here → still routes to Http
        // because the history carries a tool turn.
        $this->setupConfig();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'done']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 4, 'totalTokenCount' => 44],
                'modelVersion' => 'gemini-2.0-flash',
            ], 200),
        ]);

        $res = $this->provider()->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'find x'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'kb_search', 'arguments' => '{"q":"y"}']]]],
            ['role' => 'tool', 'content' => '{"hits":2}', 'tool_call_id' => 'c1', 'name' => 'kb_search'],
        ], []);

        $this->assertSame('done', $res->content);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            $contents = $body['contents'];

            // [0] user text, [1] model functionCall, [2] user functionResponse.
            return ! array_key_exists('tools', $body) // no tools offered on the final turn
                && count($contents) === 3
                && $contents[1]['role'] === 'model'
                // empty model text is dropped — only the functionCall part remains.
                && count($contents[1]['parts']) === 1
                && ($contents[1]['parts'][0]['functionCall']['name'] ?? null) === 'kb_search'
                && ($contents[1]['parts'][0]['functionCall']['args'] ?? null) === ['q' => 'y']
                && $contents[2]['role'] === 'user'
                // functionResponse keyed by the tool NAME (Gemini matches by name).
                && ($contents[2]['parts'][0]['functionResponse']['name'] ?? null) === 'kb_search'
                && ($contents[2]['parts'][0]['functionResponse']['response'] ?? null) === ['hits' => 2];
        });
    }

    public function test_parallel_tool_results_coalesce_into_one_user_content(): void
    {
        // Two tools called in one turn → the MCP loop replays one model message
        // (two functionCall parts) then TWO separate role:'tool' results. Gemini
        // expects the responses for a parallel call as ONE user turn carrying both
        // functionResponse parts, keeping user/model turns alternating.
        $this->setupConfig();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'done']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 60, 'candidatesTokenCount' => 4, 'totalTokenCount' => 64],
                'modelVersion' => 'gemini-2.0-flash',
            ], 200),
        ]);

        $res = $this->provider()->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'compare x and y'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'kb_search', 'arguments' => '{"q":"x"}']],
                ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'kb_lookup', 'arguments' => '{"q":"y"}']],
            ]],
            ['role' => 'tool', 'content' => '{"hits":1}', 'tool_call_id' => 'c1', 'name' => 'kb_search'],
            ['role' => 'tool', 'content' => '{"hits":2}', 'tool_call_id' => 'c2', 'name' => 'kb_lookup'],
        ], []);

        $this->assertSame('done', $res->content);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            $contents = $body['contents'];

            // [0] user text, [1] model with 2 functionCall, [2] ONE user with 2
            // functionResponse parts — NOT [2] + [3] two consecutive user turns.
            return count($contents) === 3
                && $contents[1]['role'] === 'model'
                && count($contents[1]['parts']) === 2
                && $contents[2]['role'] === 'user'
                && count($contents[2]['parts']) === 2
                && ($contents[2]['parts'][0]['functionResponse']['name'] ?? null) === 'kb_search'
                && ($contents[2]['parts'][0]['functionResponse']['response'] ?? null) === ['hits' => 1]
                && ($contents[2]['parts'][1]['functionResponse']['name'] ?? null) === 'kb_lookup'
                && ($contents[2]['parts'][1]['functionResponse']['response'] ?? null) === ['hits' => 2];
        });
    }

    public function test_mcp_final_turn_with_tool_history_and_no_tools_routes_to_http_not_sdk(): void
    {
        // No `tools` in options, but assistant tool_calls + role:'tool' history
        // the SDK can't represent → must route to raw Http:: generateContent. The
        // SDK path would throw on the 'tool' role via mapHistoryToSdkMessages().
        $this->setupConfig();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'final answer']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 5, 'totalTokenCount' => 35],
                'modelVersion' => 'gemini-2.0-flash',
            ], 200),
        ]);

        $res = $this->provider()->chatWithHistory('sys', [
            ['role' => 'user', 'content' => 'find x'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'kb', 'arguments' => '{}']]]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'name' => 'kb', 'content' => '{"r":1}'],
        ], []);

        $this->assertSame('final answer', $res->content);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), ':generateContent')
            && $req->hasHeader('x-goog-api-key', 'AIzaTest')
            && ! str_contains($req->url(), 'key='));
    }

    public function test_with_tools_chat_throws_on_http_error(): void
    {
        $this->setupConfig();
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        $this->provider()->chatWithHistory('s', [
            ['role' => 'user', 'content' => 'u'],
        ], ['tools' => [['type' => 'function', 'function' => ['name' => 'x']]]]);
    }

    public function test_no_tools_chat_still_routes_through_the_sdk_path(): void
    {
        // Regression guard: adding the with-tools routing must NOT divert a plain
        // no-tools/no-tool-history turn. Prove SDK routing structurally — the
        // with-tools branch NEVER sends `tools` / `tool_config` and NEVER emits
        // functionCall/functionResponse parts on a no-tools turn — and the SDK
        // response still maps cleanly onto AiResponse.
        $this->setupConfig();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'plain']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2, 'totalTokenCount' => 7],
                'modelVersion' => 'gemini-2.0-flash',
            ], 200),
        ]);

        $res = $this->provider()->chat('sys', 'hi');

        $this->assertSame('plain', $res->content);
        $this->assertSame([], $res->toolCalls);

        Http::assertSent(function (Request $req) {
            $body = $req->data();
            return str_contains($req->url(), ':generateContent')
                && ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_config', $body);
        });
    }
}
