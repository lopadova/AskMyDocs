<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AgentRun;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AgentRunOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_receives_tenant_scoped_pii_free_metrics_and_policy(): void
    {
        $admin = $this->user('admin');
        $mine = $this->agentRun('test-tenant', AgentRun::STATUS_COMPLETED, 3, 8);
        $mine->toolExecutions()->create([
            'logical_index' => 1,
            'tool_name' => 'list_orders',
            'tool_kind' => 'api',
            'status' => 'completed',
            'arguments_json' => ['customer_email' => 'masked@example.test'],
            'physical_request_count' => 8,
            'latency_ms' => 120,
        ]);
        $this->agentRun('other-tenant', AgentRun::STATUS_FAILED, 20, 90);

        $response = $this->actingAs($admin)->getJson('/api/admin/agent-runs/overview');

        $response->assertOk()
            ->assertJsonPath('data.metrics.runs', 1)
            ->assertJsonPath('data.metrics.logical_calls', 3)
            ->assertJsonPath('data.metrics.physical_requests', 8)
            ->assertJsonPath('data.metrics.success_rate', 100)
            ->assertJsonPath('data.policy.logical_hard', (int) config('agent.limits.logical_hard'))
            ->assertJsonPath('data.recent_runs.0.run_id', $mine->run_id);
        $json = $response->json('data');
        $this->assertArrayNotHasKey('input_json', $json['recent_runs'][0]);
        $this->assertArrayNotHasKey('result_json', $json['recent_runs'][0]);
        $this->assertStringNotContainsString('masked@example.test', json_encode($json, JSON_THROW_ON_ERROR));
    }

    public function test_viewer_cannot_read_agent_operations(): void
    {
        $this->actingAs($this->user('viewer'))
            ->getJson('/api/admin/agent-runs/overview')
            ->assertForbidden();
    }

    private function agentRun(string $tenant, string $status, int $logical, int $physical): AgentRun
    {
        return AgentRun::create([
            'run_id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $tenant,
            'project_key' => 'orders',
            'channel' => 'widget',
            'actor_type' => 'anonymous_widget',
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => $status,
            'input_json' => ['question' => 'private question'],
            'result_json' => ['response' => ['answer' => 'private answer']],
            'counters_json' => ['logical_calls' => $logical, 'physical_calls' => $physical],
            'started_at' => now()->subSeconds(2),
            'completed_at' => now(),
        ]);
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-agent-overview@example.test',
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
