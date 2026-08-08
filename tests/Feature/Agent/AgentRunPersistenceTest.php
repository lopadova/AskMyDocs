<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AgentRunPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_persists_localized_context_events_and_tool_records(): void
    {
        $user = User::create([
            'name' => 'Ada',
            'email' => 'agent-run@example.com',
            'password' => Hash::make('secret-pass-123'),
            'locale' => 'it-IT',
        ]);

        $run = AgentRun::create([
            'run_id' => '4ef62635-dc42-4f31-9767-83cddc41e4fe',
            'tenant_id' => 'test-tenant',
            'project_key' => 'orders',
            'user_id' => $user->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING,
            'budget_json' => ['logical_hard' => 25],
        ]);

        $run->events()->create([
            'sequence' => 1,
            'type' => 'run.started',
            'phase' => 'run',
            'locale' => 'it-IT',
            'message_key' => 'run.started',
            'message' => 'Avvio la ricerca.',
        ]);
        $run->toolExecutions()->create([
            'logical_index' => 1,
            'tool_name' => 'find_customer',
            'tool_kind' => 'api',
            'status' => 'completed',
            'arguments_json' => ['name' => '[REDACTED]'],
            'result_meta_json' => ['records' => 1],
            'physical_request_count' => 1,
        ]);

        $this->assertSame('it-IT', $run->fresh()->locale);
        $this->assertSame(25, $run->fresh()->budget_json['logical_hard']);
        $this->assertSame('run.started', $run->events()->sole()->message_key);
        $this->assertSame(1, $run->toolExecutions()->sole()->physical_request_count);
    }

    public function test_run_id_is_the_public_route_key_and_sequences_are_unique(): void
    {
        $run = AgentRun::create([
            'run_id' => '85af03f1-c07e-436c-a8df-779fd183e800',
            'tenant_id' => 'test-tenant',
            'channel' => 'widget',
            'actor_type' => 'anonymous_widget',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $this->assertSame($run->run_id, $run->getRouteKey());

        $run->events()->create(['sequence' => 1, 'type' => 'run.started', 'locale' => 'en']);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $run->events()->create(['sequence' => 1, 'type' => 'run.started', 'locale' => 'en']);
    }
}
