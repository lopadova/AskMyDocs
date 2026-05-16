<?php

declare(strict_types=1);

namespace Tests\Feature\V7;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Mcp\Adapters\HostBridge;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;
use Padosoft\AskMyDocsMcpPack\Support\HostChatTurn;
use Tests\TestCase;

/**
 * v7.0/W6.3 — bridge between the package orchestrator and the
 * host's `AiManager`. Exercise the round-trip translations:
 *
 *  - system prompt extraction (from first message OR from extras);
 *  - tool catalog → OpenAI function shape;
 *  - tool_choice default;
 *  - AiResponse → HostChatResponse (incl. tool calls + usage +
 *    provider/model passthrough);
 *  - provider-capability detection respects the config-driven
 *    list.
 */
final class HostBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        app(TenantContext::class)->set('default');
    }

    public function test_chat_extracts_system_message_and_forwards_tool_catalog(): void
    {
        $captured = null;
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')
            ->once()
            ->withArgs(function (string $systemPrompt, array $messages, array $options) use (&$captured): bool {
                $captured = compact('systemPrompt', 'messages', 'options');
                return true;
            })
            ->andReturn(new AiResponse(
                content: 'hello back',
                provider: 'openai',
                model: 'gpt-4o-mini',
                finishReason: 'stop',
            ));

        $bridge = new HostBridge($ai);
        $turn = new HostChatTurn(
            messages: [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => 'hello'],
            ],
            tools: [$this->readOnlyTool()],
        );

        $bridge->chat($turn);

        $this->assertSame('You are a helpful assistant.', $captured['systemPrompt']);
        $this->assertSame([['role' => 'user', 'content' => 'hello']], $captured['messages']);
        $this->assertSame('function', $captured['options']['tools'][0]['type']);
        $this->assertSame('kb.search', $captured['options']['tools'][0]['function']['name']);
        $this->assertSame('auto', $captured['options']['tool_choice'], 'tool_choice defaults to auto');
    }

    public function test_chat_omits_tools_when_catalog_is_empty(): void
    {
        // Sending `"tools": []` to OpenAI / OpenRouter is NOT the
        // same as omitting the key; some providers reject empty
        // arrays outright. The bridge passes the request through
        // as a plain completion when there's nothing to expose.
        $captured = null;
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')
            ->once()
            ->withArgs(function (string $sys, array $msgs, array $options) use (&$captured): bool {
                $captured = $options;
                return true;
            })
            ->andReturn(new AiResponse('ok', 'openai', 'gpt-4o-mini'));

        (new HostBridge($ai))->chat(new HostChatTurn(
            messages: [['role' => 'user', 'content' => 'plain']],
            tools: [],
        ));

        $this->assertArrayNotHasKey('tools', $captured);
        $this->assertArrayNotHasKey('tool_choice', $captured);
    }

    public function test_chat_falls_back_to_extras_system_prompt_when_messages_dont_carry_one(): void
    {
        $captured = null;
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')
            ->once()
            ->withArgs(function (string $systemPrompt) use (&$captured): bool {
                $captured = $systemPrompt;
                return true;
            })
            ->andReturn(new AiResponse('ok', 'openai', 'gpt-4o-mini'));

        $bridge = new HostBridge($ai);
        $turn = new HostChatTurn(
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [],
            extras: ['system_prompt' => 'You are concise.'],
        );

        $bridge->chat($turn);

        $this->assertSame('You are concise.', $captured);
    }

    public function test_chat_normalises_response_into_host_chat_response_shape(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->andReturn(new AiResponse(
            content: 'answer',
            provider: 'openai',
            model: 'gpt-4o',
            promptTokens: 17,
            completionTokens: 4,
            totalTokens: 21,
            finishReason: 'tool_calls',
            toolCalls: [[
                'id' => 'call_abc',
                'function' => [
                    'name' => 'kb.search',
                    'arguments' => '{"q":"hello"}',
                ],
            ]],
        ));

        $response = (new HostBridge($ai))->chat(new HostChatTurn(messages: [['role' => 'user', 'content' => 'q']], tools: []));

        $this->assertSame('answer', $response->content);
        $this->assertSame('openai', $response->provider);
        $this->assertSame('gpt-4o', $response->model);
        $this->assertSame('tool_calls', $response->finishReason);
        $this->assertSame(17, $response->usage['prompt_tokens']);
        $this->assertSame(21, $response->usage['total_tokens']);
        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('call_abc', $response->toolCalls[0]['id']);
        $this->assertSame('kb.search', $response->toolCalls[0]['name']);
        // JSON-string arguments must decode into an array — the
        // orchestrator dispatches the tool with this shape.
        $this->assertSame(['q' => 'hello'], $response->toolCalls[0]['arguments']);
    }

    public function test_chat_drops_unnamed_tool_calls_instead_of_crashing(): void
    {
        // A malformed provider response (missing function.name) must
        // not crash the orchestrator. The bridge silently filters.
        //
        // The third entry below is BOTH unnamed AND missing an `id`.
        // The bridge MUST validate `name` before falling back to a
        // randomly-generated id; otherwise it burns entropy (and
        // risks a `Random\RandomException` on a degraded source) for
        // a call that the next line is about to discard.
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->andReturn(new AiResponse(
            content: '',
            provider: 'openai',
            model: 'gpt-4o',
            toolCalls: [
                ['id' => 'call_a', 'function' => ['name' => 'kb.search', 'arguments' => '{}']],
                ['id' => 'call_b'], // malformed — no name, id present
                [],                  // malformed — no name AND no id (must not trigger random_bytes)
                'not-an-array',      // wrong type — must be skipped before any field access
                ['id' => 'call_c', 'function' => ['name' => ['nested', 'array'], 'arguments' => '{}']], // name as array — must NOT be cast to "Array"
                ['id' => 'call_d', 'function' => ['name' => "  \t \n", 'arguments' => '{}']], // whitespace-only name
                ['id' => 'call_e', 'function' => ['name' => 42, 'arguments' => '{}']], // numeric name — must not coerce to "42"
            ],
        ));

        $response = (new HostBridge($ai))->chat(new HostChatTurn(messages: [], tools: []));

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('kb.search', $response->toolCalls[0]['name']);
    }

    public function test_supports_tool_calling_respects_provider_config(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $bridge = new HostBridge($ai);

        config(['ai.default' =>'openai']);
        $this->assertTrue($bridge->supportsToolCalling());

        config(['ai.default' =>'openrouter']);
        $this->assertTrue($bridge->supportsToolCalling());

        config(['ai.default' =>'anthropic']);
        $this->assertFalse(
            $bridge->supportsToolCalling(),
            'Anthropic uses a different tool-calling shape; the OpenAI-style bridge does not support it yet',
        );

        config(['ai.default' =>'gemini']);
        $this->assertFalse($bridge->supportsToolCalling());
    }

    private function readOnlyTool(): McpToolContract
    {
        return new class implements McpToolContract {
            public function name(): string { return 'kb.search'; }
            public function description(): string { return 'search KB'; }
            public function schema(): array { return ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]; }
            public function isIdempotent(): bool { return true; }
            public function isReadOnly(): bool { return true; }
            public function invoke(array $arguments): mixed { return []; }
        };
    }
}
