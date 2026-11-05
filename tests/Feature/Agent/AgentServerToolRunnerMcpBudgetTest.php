<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Tools\AgentServerToolRunner;
use App\Agent\Tools\AgentToolDefinition;
use App\Agent\Tools\AgentToolRegistry;
use App\Models\AgentRun;
use App\Models\McpServer;
use App\Models\McpToolCallAudit;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsMcpPack\Contracts\McpTransportContract;
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Padosoft\AskMyDocsMcpPack\Support\JsonRpcMessage;
use Tests\Support\Mcp\StubMcpTransport;
use Tests\TestCase;

/**
 * askmydocs-mcp-pack 2.0 negotiates before every tools/call, so one MCP tool
 * call is several wire messages, not one, and how many depends on the server
 * (a current server, a legacy one, a legacy one that rejects the first protocol
 * revision, a session that has to be recovered). The agent budget checks the
 * worst case before any traffic and charges what the pack really sent.
 */
final class AgentServerToolRunnerMcpBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const HARD_LIMIT = 30;

    /** discover (unsupported) + initialize + notification + tools/call against the stub. */
    private const STUB_LEGACY_MESSAGES = 4;

    private User $user;

    private McpServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        app(TenantContext::class)->set('default');
        config([
            'mcp.enabled' => true,
            'mcp-pack.audit_model' => McpToolCallAudit::class,
            'agent.limits.physical_hard' => self::HARD_LIMIT,
        ]);

        $this->user = User::create([
            'name' => 'Budget admin',
            'email' => 'budget-admin-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
        $this->user->assignRole('super-admin');
        $this->server = McpServer::create([
            'tenant_id' => 'default',
            'name' => 'Test Upstream',
            'transport' => McpServer::TRANSPORT_HTTP,
            'endpoint' => 'http://upstream.test/mcp',
            'auth_config_encrypted' => null,
            'enabled_tools_json' => ['kb_search'],
            'handshake_response_json' => ['tools' => [[
                'name' => 'kb_search',
                'description' => 'Search the knowledge base',
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ]]],
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $this->user->id,
        ]);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_the_registry_declares_an_mcp_call_from_the_cheapest_to_the_worst_case(): void
    {
        $tool = $this->tool();

        $this->assertSame(AgentToolRegistry::MCP_PHYSICAL_MINIMUM, $tool->physicalMinimum);
        $this->assertSame(AgentToolRegistry::MCP_PHYSICAL_LIKELY, $tool->physicalLikely);
        $this->assertSame(AgentToolRegistry::mcpPhysicalMaximum(), $tool->physicalMaximum);
        $this->assertGreaterThan(1, $tool->physicalMinimum, 'a call is never a single message under pack 2.0');
    }

    public function test_the_worst_case_covers_every_legacy_revision_and_a_session_recovery(): void
    {
        // discover + one initialize per revision + notification + tools/call,
        // then recovery: initialize + notification + tools/call.
        $expected = 1 + count(McpClient::SUPPORTED_LEGACY_PROTOCOL_VERSIONS) + 1 + 1 + 3;

        $this->assertSame($expected, AgentToolRegistry::mcpPhysicalMaximum());
        $this->assertGreaterThan(4, AgentToolRegistry::mcpPhysicalMaximum(), 'four only covers the first legacy revision succeeding');
    }

    public function test_a_successful_call_is_charged_what_the_pack_sent(): void
    {
        $this->stubTransport();
        $run = $this->agentRun(['physical_calls' => 0]);
        $budget = new AgentBudgetTracker($run);

        $result = $this->runner()->execute($this->tool(), ['query' => 'hello'], $this->context($run), $run, $budget);

        $this->assertTrue($result->successful());
        $this->assertSame(self::STUB_LEGACY_MESSAGES, $result->physicalRequests);
        $this->assertSame(self::STUB_LEGACY_MESSAGES, $budget->snapshot()['physical_calls']);
    }

    public function test_a_failed_call_is_still_charged_what_the_pack_sent(): void
    {
        McpClient::useTransportResolver(fn () => (new StubMcpTransport())->scriptInitialize());
        $run = $this->agentRun(['physical_calls' => 0]);
        $budget = new AgentBudgetTracker($run);

        $result = $this->runner()->execute($this->tool(), ['query' => 'hello'], $this->context($run), $run, $budget);

        $this->assertFalse($result->successful());
        $this->assertSame('mcp_tool_error', $result->stopReason);
        $this->assertSame(self::STUB_LEGACY_MESSAGES, $result->physicalRequests);
        $this->assertSame(self::STUB_LEGACY_MESSAGES, $budget->snapshot()['physical_calls']);
    }

    public function test_a_legacy_server_that_rejects_the_first_revision_costs_more_than_four(): void
    {
        $transport = $this->rejectFirstRevisionTransport();
        McpClient::useTransportResolver(fn () => $transport);
        $run = $this->agentRun(['physical_calls' => 0]);
        $budget = new AgentBudgetTracker($run);

        $result = $this->runner()->execute($this->tool(), ['query' => 'hello'], $this->context($run), $run, $budget);

        // discover, initialize (rejected), initialize (accepted), notification, tools/call
        $this->assertTrue($result->successful());
        $this->assertSame(5, $transport->messages);
        $this->assertSame(5, $result->physicalRequests);
        $this->assertSame(5, $budget->snapshot()['physical_calls']);
    }

    public function test_a_call_is_refused_before_any_traffic_when_fewer_slots_remain_than_the_worst_case(): void
    {
        $used = self::HARD_LIMIT - (AgentToolRegistry::mcpPhysicalMaximum() - 1);
        $run = $this->agentRun(['physical_calls' => $used]);
        $budget = new AgentBudgetTracker($run);
        $touched = false;
        McpClient::useTransportResolver(function () use (&$touched) {
            $touched = true;

            return (new StubMcpTransport())->scriptInitialize();
        });

        $result = $this->runner()->execute($this->tool(), ['query' => 'hello'], $this->context($run), $run, $budget);

        $this->assertFalse($touched, 'no message may be sent once the worst case no longer fits');
        $this->assertFalse($result->complete);
        $this->assertSame('physical_hard_limit', $result->stopReason);
        $this->assertSame(0, $result->physicalRequests);
        $this->assertSame($used, $budget->snapshot()['physical_calls']);
    }

    public function test_four_remaining_slots_are_not_enough_for_a_call_that_may_need_five(): void
    {
        $used = self::HARD_LIMIT - 4;
        $run = $this->agentRun(['physical_calls' => $used]);
        $budget = new AgentBudgetTracker($run);
        $transport = $this->rejectFirstRevisionTransport();
        McpClient::useTransportResolver(fn () => $transport);

        $result = $this->runner()->execute($this->tool(), ['query' => 'hello'], $this->context($run), $run, $budget);

        $this->assertSame(0, $transport->messages);
        $this->assertSame('physical_hard_limit', $result->stopReason);
        $this->assertSame($used, $budget->snapshot()['physical_calls']);
    }

    public function test_a_call_that_exactly_fits_the_worst_case_is_allowed(): void
    {
        $this->stubTransport();
        $used = self::HARD_LIMIT - AgentToolRegistry::mcpPhysicalMaximum();
        $run = $this->agentRun(['physical_calls' => $used]);
        $budget = new AgentBudgetTracker($run);

        $result = $this->runner()->execute($this->tool(), ['query' => 'hello'], $this->context($run), $run, $budget);

        $this->assertTrue($result->successful());
        $this->assertSame($used + self::STUB_LEGACY_MESSAGES, $budget->snapshot()['physical_calls']);
    }

    private function stubTransport(): void
    {
        $transport = (new StubMcpTransport())->scriptInitialize()->scriptToolCall('kb_search', [
            'hits' => [['title' => 'Hello World', 'score' => 0.91]],
        ]);
        McpClient::useTransportResolver(fn () => $transport);
    }

    /**
     * A legacy server that has no `server/discover`, rejects the newest protocol
     * revision as unsupported and accepts the next one.
     */
    private function rejectFirstRevisionTransport(): McpTransportContract
    {
        return new class implements McpTransportContract {
            public int $messages = 0;

            private int $initializeCalls = 0;

            public function request(JsonRpcMessage $request): JsonRpcMessage
            {
                $this->messages++;

                return match ($request->method) {
                    'initialize' => ++$this->initializeCalls === 1
                        ? JsonRpcMessage::errorResponse($request->id, -32602, 'Unsupported protocol version')
                        : JsonRpcMessage::response($request->id, ['capabilities' => ['tools' => new \stdClass()]]),
                    'tools/call' => JsonRpcMessage::response($request->id, ['hits' => [['title' => 'Hello World']]]),
                    default => JsonRpcMessage::errorResponse($request->id, -32601, 'Method not found'),
                };
            }

            public function notify(JsonRpcMessage $notification): void
            {
                $this->messages++;
            }

            public function isHealthy(): bool
            {
                return true;
            }
        };
    }

    private function runner(): AgentServerToolRunner
    {
        return $this->app->make(AgentServerToolRunner::class);
    }

    /** The definition the registry really produces for the advertised tool. */
    private function tool(): AgentToolDefinition
    {
        $run = $this->agentRun([]);
        $tools = $this->app->make(AgentToolRegistry::class)->forContext($this->context($run), $this->user);

        $this->assertArrayHasKey('kb_search', $tools, 'the registry must register the server\'s advertised MCP tool');
        $this->assertSame('mcp', $tools['kb_search']->kind);

        return $tools['kb_search'];
    }

    private function context(AgentRun $run): AgentExecutionContext
    {
        return new AgentExecutionContext(
            runId: $run->run_id,
            tenantId: 'default',
            projectKey: null,
            channel: 'chat',
            actorType: 'user',
            actorId: (string) $this->user->id,
            locale: 'it-IT',
            timezone: 'Europe/Rome',
        );
    }

    /** @param array<string,mixed> $counters */
    private function agentRun(array $counters): AgentRun
    {
        return AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'default',
            'user_id' => $this->user->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING,
            'started_at' => now(),
            'counters_json' => $counters,
            'budget_json' => [],
        ]);
    }
}
