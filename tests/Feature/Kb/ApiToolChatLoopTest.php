<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Mcp\Client\McpToolCallingService;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Tests\TestCase;

/**
 * Connettore API — proves an active API route surfaces as a live LLM tool in
 * the chat loop and is executed server-side, on top of (independent of) the
 * external-MCP tool source. Drives the real {@see McpToolCallingService} over a
 * faked OpenRouter `/chat/completions` (tool_call → final answer) and a faked
 * external "orders" endpoint. mcp.enabled is OFF here to prove the API-tool
 * source stands alone (R43 — the feature works without any MCP server).
 */
final class ApiToolChatLoopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.default', 'openrouter');
        config()->set('ai.providers.openrouter', [
            'driver' => 'openrouter',
            'name' => 'openrouter',
            'key' => 'or-test',
            'url' => 'https://openrouter.ai/api/v1',
            'timeout' => 30,
            'temperature' => 0.2,
            'max_tokens' => 1024,
            'models' => ['text' => ['default' => 'openai/gpt-4o-mini']],
        ]);
        config()->set('mcp.enabled', false);
        config()->set('mcp.tool_calling.max_iterations', 3);
        config()->set('connector-api.chat_tools.enabled', true);
        // Deterministic SSRF: keep the guard ON but skip DNS for the fake host.
        config()->set('connector-api.ssrf.resolve_dns', false);
    }

    public function test_active_api_route_is_called_as_a_tool_during_chat(): void
    {
        $user = User::create([
            'name' => 'Op',
            'email' => 'op@example.com',
            'password' => Hash::make('secret-pass-123'),
            'locale' => 'it-IT',
        ]);

        $this->seedOrdersRoute();

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->toolCallTurn(), 200)
                ->push($this->finalAnswerTurn('Order 10293 is shipped.'), 200),
            'api.example.test/*' => Http::response(
                ['orders' => [['id' => '10293', 'status' => 'shipped', 'total' => 42]]],
                200,
            ),
        ]);

        $service = app(McpToolCallingService::class);

        $response = $service->chatWithTools(
            systemPrompt: 'You are a helpful assistant.',
            messages: [['role' => 'user', 'content' => 'What is the status of order 10293?']],
            options: [],
            user: $user,
            context: ['project_key' => null],
        );

        // The LLM's final answer is returned.
        $this->assertStringContainsString('shipped', $response->content);

        // The external endpoint was actually hit, server-side, with the llm arg.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.example.test/orders')
            && str_contains($request->url(), 'order_id=10293')
            && $request->header('Accept-Language')[0] === 'it-IT');

        // A sanitised tool-call log row was written (spec §9 observability).
        $this->assertDatabaseHas('api_tool_call_logs', [
            'tenant_id' => app(TenantContext::class)->current(),
            'response_status' => 200,
        ]);
    }

    public function test_api_tools_are_not_injected_when_chat_tools_disabled(): void
    {
        config()->set('connector-api.chat_tools.enabled', false);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op2@example.com',
            'password' => Hash::make('secret-pass-123'),
        ]);
        $this->seedOrdersRoute();

        // With the flag OFF and no MCP servers, there are no tools — the loop
        // falls back to a plain chat turn (no tool_calls in the response).
        Http::fake([
            'openrouter.ai/*' => Http::response($this->finalAnswerTurn('I cannot fetch live orders.'), 200),
            'api.example.test/*' => Http::response(['orders' => []], 200),
        ]);

        $response = app(McpToolCallingService::class)->chatWithTools(
            systemPrompt: 'You are a helpful assistant.',
            messages: [['role' => 'user', 'content' => 'Status of order 10293?']],
            options: [],
            user: $user,
            context: ['project_key' => null],
        );

        $this->assertStringContainsString('cannot fetch', $response->content);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.example.test'));
        $this->assertDatabaseCount('api_tool_call_logs', 0);
    }

    public function test_api_tools_can_chain_a_lookup_into_a_dependent_request(): void
    {
        $user = User::create([
            'name' => 'Op',
            'email' => 'chain@example.com',
            'password' => Hash::make('secret-pass-123'),
        ]);

        $this->seedCustomerOrderRoutes();

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->namedToolCallTurn('call_customer', 'find_customer', ['name' => 'Tizio']), 200)
                ->push($this->namedToolCallTurn('call_orders', 'get_customer_orders', ['customer_id' => 'cust-7']), 200)
                ->push($this->finalAnswerTurn('Tizio has two open orders.'), 200),
            'api.example.test/customers*' => Http::response(['items' => [['id' => 'cust-7', 'name' => 'Tizio']]], 200),
            'api.example.test/orders*' => Http::response(['items' => [['id' => 'ord-1'], ['id' => 'ord-2']]], 200),
        ]);

        $response = app(McpToolCallingService::class)->chatWithTools(
            systemPrompt: 'Use live tools when needed.',
            messages: [['role' => 'user', 'content' => 'Dammi gli ordini di Tizio']],
            user: $user,
            context: ['project_key' => null],
        );

        $this->assertSame('Tizio has two open orders.', $response->content);
        $this->assertCount(2, $response->toolCalls);
        $this->assertSame(['find_customer', 'get_customer_orders'], array_column($response->toolCalls, 'name'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/customers')
            && str_contains($request->url(), 'name=Tizio'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/orders')
            && str_contains($request->url(), 'customer_id=cust-7'));
    }

    private function seedOrdersRoute(): void
    {
        $connector = ApiConnector::create([
            'name' => 'ERP',
            'project_key' => null,
            'is_active' => true,
        ]);

        $route = ApiRoute::create([
            'api_connector_id' => $connector->id,
            'project_key' => '',
            'name' => 'Ordini',
            'slug' => 'get_orders',
            'description' => 'Fetch live order status from the ERP.',
            'http_method' => 'GET',
            'url' => 'https://api.example.test/orders',
            'mode' => 'tool',
            'status' => 'active',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['order_id' => ['type' => 'string', 'description' => 'Order id']],
                'required' => [],
            ],
            'tool_definition' => [
                'name' => 'get_orders',
                'description' => 'Fetch live order status from the ERP.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['order_id' => ['type' => 'string', 'description' => 'Order id']],
                    'required' => [],
                ],
            ],
        ]);

        ApiRouteParameter::create([
            'api_route_id' => $route->id,
            'name' => 'order_id',
            'location' => 'query',
            'source' => 'llm',
            'type' => 'string',
            'required' => false,
        ]);
    }

    private function seedCustomerOrderRoutes(): void
    {
        $connector = ApiConnector::create([
            'name' => 'CRM + ERP',
            'project_key' => null,
            'is_active' => true,
        ]);

        foreach ([
            ['name' => 'Clienti', 'slug' => 'find_customer', 'url' => 'https://api.example.test/customers', 'param' => 'name'],
            ['name' => 'Ordini cliente', 'slug' => 'get_customer_orders', 'url' => 'https://api.example.test/orders', 'param' => 'customer_id'],
        ] as $definition) {
            $route = ApiRoute::create([
                'api_connector_id' => $connector->id,
                'project_key' => '',
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'description' => $definition['name'],
                'http_method' => 'GET',
                'url' => $definition['url'],
                'mode' => 'tool',
                'status' => 'active',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [$definition['param'] => ['type' => 'string']],
                    'required' => [$definition['param']],
                ],
                'tool_definition' => [
                    'name' => $definition['slug'],
                    'description' => $definition['name'],
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [$definition['param'] => ['type' => 'string']],
                        'required' => [$definition['param']],
                    ],
                ],
            ]);

            ApiRouteParameter::create([
                'api_route_id' => $route->id,
                'name' => $definition['param'],
                'location' => 'query',
                'source' => 'llm',
                'type' => 'string',
                'required' => true,
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function toolCallTurn(): array
    {
        return [
            'model' => 'openai/gpt-4o-mini',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'get_orders', 'arguments' => '{"order_id":"10293"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    /** @param array<string,mixed> $arguments */
    private function namedToolCallTurn(string $id, string $name, array $arguments): array
    {
        return [
            'model' => 'openai/gpt-4o-mini',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => $id,
                        'type' => 'function',
                        'function' => [
                            'name' => $name,
                            'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    /** @return array<string,mixed> */
    private function finalAnswerTurn(string $content): array
    {
        return [
            'model' => 'openai/gpt-4o-mini',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 6, 'total_tokens' => 18],
        ];
    }
}
