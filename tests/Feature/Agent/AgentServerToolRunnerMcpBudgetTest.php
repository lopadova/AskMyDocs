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
use Padosoft\AskMyDocsMcpPack\Services\McpClient;
use Tests\Support\Mcp\StubMcpTransport;
use Tests\TestCase;

/**
 * askmydocs-mcp-pack 2.0 negotiates before every tools/call, so one MCP tool
 * call is several wire messages, not one. The agent budget has to charge and
 * enforce that cost or a run with a single slot left silently exceeds its
 * physical limit.
 */
final class AgentServerToolRunnerMcpBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const HARD_LIMIT = 10;

    private User $user;

    private McpServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        app(TenantContext::class)->set('default');
        config([
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
            'status' => McpServer::STATUS_ACTIVE,
            'created_by' => $this->user->id,
        ]);
    }

    protected function tearDown(): void
    {
        McpClient::useTransportResolver(null);
        parent::tearDown();
    }

    public function test_the_registry_plans_an_mcp_call_as_negotiation_plus_the_call(): void
    {
        $tool = $this->tool();

        $this->assertSame(AgentToolRegistry::MCP_PHYSICAL_MINIMUM, $tool->physicalMinimum);
        $this->assertSame(AgentToolRegistry::MCP_PHYSICAL_MAXIMUM, $tool->physicalMaximum);
        $this->assertGreaterThan(1, $tool->physicalMinimum, 'a call is never a single wire message under pack 2.0');
        $this->assertGreaterThan(AgentToolRegistry::MCP_PHYSICAL_LIKELY - 1, AgentToolRegistry::MCP_PHYSICAL_MAXIMUM - 1);
    }

    public function test_a_successful_call_is_charged_its_worst_case_wire_cost(): void
    {
        $this->transport();
        $run = $this->agentRun(['physical_calls' => 0]);
        $budget = new AgentBudgetTracker($run);

        $result = $this->runner()->execute($this->tool(), ['query' => 'hello'], $this->context($run), $run, $budget);

        $this->assertTrue($result->successful());
        $this->assertSame(AgentToolRegistry::MCP_PHYSICAL_MAXIMUM, $result->physicalRequests);
        $this->assertSame(AgentToolRegistry::MCP_PHYSICAL_MAXIMUM, $budget->snapshot()['physical_calls']);
    }

    public function test_a_failed_call_is_still_charged_its_worst_case_wire_cost(): void
    {
        McpClient::useTransportResolver(fn () => (new StubMcpTransport())->scriptInitialize());
        $run = $this->agentRun(['physical_calls' => 0]);
        $budget = new AgentBudgetTracker($run);

        $result = $this->runner()->execute($this->tool(), ['query' => 'hello'], $this->context($run), $run, $budget);

        $this->assertFalse($result->successful());
        $this->assertSame('mcp_tool_error', $result->stopReason);
        $this->assertSame(AgentToolRegistry::MCP_PHYSICAL_MAXIMUM, $result->physicalRequests);
        $this->assertSame(AgentToolRegistry::MCP_PHYSICAL_MAXIMUM, $budget->snapshot()['physical_calls']);
    }

    public function test_a_call_is_refused_before_any_traffic_when_fewer_slots_remain_than_its_worst_case(): void
    {
        $used = self::HARD_LIMIT - (AgentToolRegistry::MCP_PHYSICAL_MAXIMUM - 1);
        $run = $this->agentRun(['physical_calls' => $used]);
        $budget = new AgentBudgetTracker($run);
        $touched = false;
        McpClient::useTransportResolver(function () use (&$touched) {
            $touched = true;

            return (new StubMcpTransport())->scriptInitialize();
        });

        $result = $this->runner()->execute($this->tool(), ['query' => 'hello'], $this->context($run), $run, $budget);

        $this->assertFalse($touched, 'no wire message may be sent once the worst case no longer fits');
        $this->assertFalse($result->complete);
        $this->assertSame('physical_hard_limit', $result->stopReason);
        $this->assertSame(0, $result->physicalRequests);
        $this->assertSame($used, $budget->snapshot()['physical_calls']);
    }

    public function test_a_call_that_exactly_fits_is_allowed_and_fills_the_budget(): void
    {
        $this->transport();
        $used = self::HARD_LIMIT - AgentToolRegistry::MCP_PHYSICAL_MAXIMUM;
        $run = $this->agentRun(['physical_calls' => $used]);
        $budget = new AgentBudgetTracker($run);

        $result = $this->runner()->execute($this->tool(), ['query' => 'hello'], $this->context($run), $run, $budget);

        $this->assertTrue($result->successful());
        $this->assertSame(self::HARD_LIMIT, $budget->snapshot()['physical_calls']);
    }

    private function transport(): void
    {
        $transport = (new StubMcpTransport())->scriptInitialize()->scriptToolCall('kb_search', [
            'hits' => [['title' => 'Hello World', 'score' => 0.91]],
        ]);
        McpClient::useTransportResolver(fn () => $transport);
    }

    private function runner(): AgentServerToolRunner
    {
        return $this->app->make(AgentServerToolRunner::class);
    }

    private function tool(): AgentToolDefinition
    {
        return new AgentToolDefinition(
            name: 'kb_search',
            displayName: 'kb_search',
            description: 'Search',
            kind: 'mcp',
            inputSchema: ['type' => 'object', 'properties' => []],
            readOnly: true,
            idempotent: true,
            physicalMinimum: AgentToolRegistry::MCP_PHYSICAL_MINIMUM,
            physicalLikely: AgentToolRegistry::MCP_PHYSICAL_LIKELY,
            physicalMaximum: AgentToolRegistry::MCP_PHYSICAL_MAXIMUM,
            executorReference: $this->server->id,
        );
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
