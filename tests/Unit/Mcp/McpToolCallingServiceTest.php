<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Mcp\Client\McpClientBridge;
use App\Mcp\Client\McpToolAuthorizer;
use App\Mcp\Client\McpToolCallingService;
use App\Mcp\Client\Registry\McpServerRegistry;
use App\Mcp\Client\ToolInvoker;
use App\Models\McpServer;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class McpToolCallingServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');

        $this->admin = User::create([
            'name' => 'Mcp Tool Caller',
            'email' => 'mcp-tool-caller-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_can_handle_tool_calling_returns_false_when_disabled(): void
    {
        config()->set('mcp.enabled', false);

        $service = new McpToolCallingService(
            ai: $this->makeAiManager('openai'),
            registry: new McpServerRegistry(app(TenantContext::class)),
            invoker: new ToolInvoker(new McpClientBridge()),
            authorizer: new McpToolAuthorizer(),
        );

        $this->assertFalse($service->canHandleToolCalling($this->admin));
    }

    public function test_can_handle_tool_calling_returns_false_for_unsupported_provider(): void
    {
        config()->set('mcp.enabled', true);

        $service = new McpToolCallingService(
            ai: $this->makeAiManager('anthropic'),
            registry: new McpServerRegistry(app(TenantContext::class)),
            invoker: new ToolInvoker(new McpClientBridge()),
            authorizer: new McpToolAuthorizer(),
        );

        $this->assertFalse($service->canHandleToolCalling($this->admin));
    }

    public function test_chat_with_tools_falls_back_to_plain_chat_when_tool_calling_not_available(): void
    {
        config()->set('mcp.enabled', false);
        $ai = $this->makeAiManager('openai');

        $expected = new AiResponse(content: 'plain', provider: 'openai', model: 'gpt-4o');
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($expected);

        $service = new McpToolCallingService(
            ai: $ai,
            registry: new McpServerRegistry(app(TenantContext::class)),
            invoker: new ToolInvoker(new McpClientBridge()),
            authorizer: new McpToolAuthorizer(),
        );

        $result = $service->chatWithTools(
            systemPrompt: 'sys',
            messages: [['role' => 'user', 'content' => 'hi']],
            user: $this->admin,
        );

        $this->assertSame('plain', $result->content);
    }

    public function test_chat_with_tools_executes_one_tool_call_and_returns_final_response(): void
    {
        config()->set('mcp.enabled', true);
        config()->set('mcp.tool_calling.max_iterations', 2);

        $this->createServerWithHandshakeTools([
            [
                'name' => 'search_docs',
                'description' => 'Search documents',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                ],
            ],
        ], ['search_docs']);

        Http::fake([
            'http://127.0.0.1:3535/invoke-tool' => Http::response(['hits' => [['id' => 1]]], 200),
        ]);

        $ai = $this->makeAiManager('openai');
        $turn1 = new AiResponse(
            content: '',
            provider: 'openai',
            model: 'gpt-4o',
            toolCalls: [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => [
                    'name' => 'search_docs',
                    'arguments' => '{"query":"release notes"}',
                ],
            ]],
        );
        $turn2 = new AiResponse(content: 'final answer', provider: 'openai', model: 'gpt-4o');
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn($turn1, $turn2);

        $service = new McpToolCallingService(
            ai: $ai,
            registry: new McpServerRegistry(app(TenantContext::class)),
            invoker: new ToolInvoker(new McpClientBridge()),
            authorizer: new McpToolAuthorizer(),
        );

        $result = $service->chatWithTools(
            systemPrompt: 'sys',
            messages: [['role' => 'user', 'content' => 'summarize releases']],
            user: $this->admin,
        );

        $this->assertSame('final answer', $result->content);
        $this->assertCount(1, $result->toolCalls);
        $this->assertSame('ok', $result->toolCalls[0]['status']);
        $this->assertSame('search_docs', $result->toolCalls[0]['name']);
        Http::assertSent(static function (\Illuminate\Http\Client\Request $request): bool {
            return $request->url() === 'http://127.0.0.1:3535/invoke-tool'
                && $request['tool_name'] === 'search_docs'
                && $request['input'] === ['query' => 'release notes'];
        });
    }

    public function test_chat_with_tools_continues_after_unconfigured_tool_in_same_turn(): void
    {
        config()->set('mcp.enabled', true);
        config()->set('mcp.tool_calling.max_iterations', 2);

        $this->createServerWithHandshakeTools([
            [
                'name' => 'search_docs',
                'description' => 'Search documents',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                ],
            ],
            [
                'name' => 'graph',
                'description' => 'Graph lookup',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['slug' => ['type' => 'string']],
                ],
            ],
        ], ['search_docs']);

        Http::fake([
            'http://127.0.0.1:3535/invoke-tool' => Http::response(['hits' => [['id' => 1]]], 200),
        ]);

        $ai = $this->makeAiManager('openai');
        $turns = [];
        $turn1 = new AiResponse(
            content: '',
            provider: 'openai',
            model: 'gpt-4o',
            toolCalls: [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_docs',
                        'arguments' => '{"query":"release notes"}',
                    ],
                ],
                [
                    'id' => 'call_2',
                    'type' => 'function',
                    'function' => [
                        'name' => 'graph',
                        'arguments' => '{"slug":"release-notes"}',
                    ],
                ],
            ],
        );
        $turn2 = new AiResponse(content: 'final answer', provider: 'openai', model: 'gpt-4o');
        $ai->shouldReceive('chatWithHistory')
            ->twice()
            ->andReturnUsing(function (string $systemPrompt, array $messages, array $options) use (&$turns, $turn1, $turn2) {
                $turns[] = ['messages' => $messages, 'options' => $options];

                return count($turns) === 1 ? $turn1 : $turn2;
            });

        $service = new McpToolCallingService(
            ai: $ai,
            registry: new McpServerRegistry(app(TenantContext::class)),
            invoker: new ToolInvoker(new McpClientBridge()),
            authorizer: new McpToolAuthorizer(),
        );

        $result = $service->chatWithTools(
            systemPrompt: 'sys',
            messages: [['role' => 'user', 'content' => 'summarize releases']],
            user: $this->admin,
        );

        $this->assertSame('final answer', $result->content);
        $this->assertCount(2, $result->toolCalls);

        $toolCallsByName = collect($result->toolCalls)->keyBy('name');
        $this->assertSame('ok', $toolCallsByName['search_docs']['status']);
        $this->assertSame('error', $toolCallsByName['graph']['status']);
        $this->assertStringContainsString('not configured for the current tenant', $toolCallsByName['graph']['error']);

        $secondTurnMessages = $turns[1]['messages'];
        $toolMessages = array_values(array_filter($secondTurnMessages, static fn (array $message): bool => ($message['role'] ?? null) === 'tool'));
        $this->assertCount(2, $toolMessages);
        $this->assertSame(['search_docs', 'graph'], array_column($toolMessages, 'name'));
        $this->assertStringContainsString('"hits":[{"id":1}]', $toolMessages[0]['content']);
        $this->assertStringContainsString('not configured for the current tenant', $toolMessages[1]['content']);
        Http::assertSentCount(1);
    }

    public function test_chat_with_tools_carries_last_iteration_results_into_final_turn(): void
    {
        config()->set('mcp.enabled', true);
        config()->set('mcp.tool_calling.max_iterations', 2);

        $this->createServerWithHandshakeTools([
            [
                'name' => 'search_docs',
                'description' => 'Search documents',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                ],
            ],
        ], ['search_docs']);

        Http::fake([
            'http://127.0.0.1:3535/invoke-tool' => Http::sequence()
                ->push(['hits' => [['id' => 1]]], 200)
                ->push(['hits' => [['id' => 2]]], 200),
        ]);

        $ai = $this->makeAiManager('openai');
        $turns = [];
        $turn1 = new AiResponse(
            content: '',
            provider: 'openai',
            model: 'gpt-4o',
            toolCalls: [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => [
                    'name' => 'search_docs',
                    'arguments' => '{"query":"alpha"}',
                ],
            ]],
        );
        $turn2 = new AiResponse(
            content: '',
            provider: 'openai',
            model: 'gpt-4o',
            toolCalls: [[
                'id' => 'call_2',
                'type' => 'function',
                'function' => [
                    'name' => 'search_docs',
                    'arguments' => '{"query":"beta"}',
                ],
            ]],
        );
        $turn3 = new AiResponse(content: 'final answer', provider: 'openai', model: 'gpt-4o');
        $ai->shouldReceive('chatWithHistory')
            ->times(3)
            ->andReturnUsing(function (string $systemPrompt, array $messages, array $options) use (&$turns, $turn1, $turn2, $turn3) {
                $turns[] = ['messages' => $messages, 'options' => $options];

                return match (count($turns)) {
                    1 => $turn1,
                    2 => $turn2,
                    default => $turn3,
                };
            });

        $service = new McpToolCallingService(
            ai: $ai,
            registry: new McpServerRegistry(app(TenantContext::class)),
            invoker: new ToolInvoker(new McpClientBridge()),
            authorizer: new McpToolAuthorizer(),
        );

        $result = $service->chatWithTools(
            systemPrompt: 'sys',
            messages: [['role' => 'user', 'content' => 'summarize releases']],
            user: $this->admin,
        );

        $this->assertSame('final answer', $result->content);
        $this->assertCount(2, $result->toolCalls);
        $this->assertSame(['call_1', 'call_2'], array_column($result->toolCalls, 'id'));
        $this->assertSame(['ok', 'ok'], array_column($result->toolCalls, 'status'));

        $finalTurnMessages = $turns[2]['messages'];
        $toolMessages = array_values(array_filter($finalTurnMessages, static fn (array $message): bool => ($message['role'] ?? null) === 'tool'));
        $this->assertCount(2, $toolMessages);
        $this->assertSame(['call_1', 'call_2'], array_column($toolMessages, 'tool_call_id'));
        Http::assertSentCount(2);
    }

    public function test_can_handle_tool_calling_returns_false_when_handshake_advertises_only_malformed_tools(): void
    {
        config()->set('mcp.enabled', true);

        $this->createServerWithHandshakeTools([
            [
                'description' => 'Missing a name field',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                ],
            ],
        ], ['*']);

        $service = new McpToolCallingService(
            ai: $this->makeAiManager('openai'),
            registry: new McpServerRegistry(app(TenantContext::class)),
            invoker: new ToolInvoker(new McpClientBridge()),
            authorizer: new McpToolAuthorizer(),
        );

        $this->assertFalse($service->canHandleToolCalling($this->admin));
    }

    public function test_chat_with_tools_queries_mcp_servers_once_per_request(): void
    {
        config()->set('mcp.enabled', true);
        config()->set('mcp.tool_calling.max_iterations', 1);

        $this->createServerWithHandshakeTools([
            [
                'name' => 'search_docs',
                'description' => 'Search documents',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                ],
            ],
        ], ['search_docs']);

        Http::fake([
            'http://127.0.0.1:3535/invoke-tool' => Http::response(['hits' => [['id' => 1]]], 200),
        ]);

        $ai = $this->makeAiManager('openai');
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            new AiResponse(
                content: '',
                provider: 'openai',
                model: 'gpt-4o',
                toolCalls: [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_docs',
                        'arguments' => '{"query":"release notes"}',
                    ],
                ]],
            ),
            new AiResponse(content: 'final answer', provider: 'openai', model: 'gpt-4o'),
        );

        $service = new McpToolCallingService(
            ai: $ai,
            registry: new McpServerRegistry(app(TenantContext::class)),
            invoker: new ToolInvoker(new McpClientBridge()),
            authorizer: new McpToolAuthorizer(),
        );

        DB::flushQueryLog();
        DB::enableQueryLog();

        $service->chatWithTools(
            systemPrompt: 'sys',
            messages: [['role' => 'user', 'content' => 'summarize releases']],
            user: $this->admin,
        );

        $mcpServerQueries = array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains(strtolower((string) ($query['query'] ?? '')), 'mcp_servers')
        ));

        $this->assertCount(1, $mcpServerQueries);
    }

    private function makeAiManager(string $providerName): AiManager
    {
        $provider = new class($providerName) implements AiProviderInterface {
            public function __construct(private readonly string $name) {}
            public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
            {
                return new AiResponse(content: '', provider: $this->name(), model: 'x');
            }
            public function chatWithHistory(string $systemPrompt, array $messages, array $options = []): AiResponse
            {
                return new AiResponse(content: '', provider: $this->name(), model: 'x');
            }
            public function chatStream(string $systemPrompt, array $messages, array $options = []): \Generator
            {
                if (false) {
                    yield;
                }
            }
            public function generateEmbeddings(array $texts): \App\Ai\EmbeddingsResponse
            {
                return new \App\Ai\EmbeddingsResponse([], $this->name(), null);
            }
            public function name(): string
            {
                return $this->name;
            }
            public function supportsEmbeddings(): bool
            {
                return true;
            }
        };

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);

        return $ai;
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @param  list<string>  $enabledTools
     */
    private function createServerWithHandshakeTools(array $tools, array $enabledTools): McpServer
    {
        return McpServer::create([
            'tenant_id' => 'default',
            'name' => 'kb-sidecar',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://127.0.0.1:3535',
            'enabled_tools_json' => $enabledTools,
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
            'handshake_response_json' => [
                'tools' => $tools,
            ],
        ]);
    }
}
