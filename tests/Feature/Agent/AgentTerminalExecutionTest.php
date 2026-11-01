<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentEventPublisher;
use App\Agent\AgentRunControl;
use App\Agent\AgentTerminalExecutionFinalizer;
use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AgentTerminalExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_exhausted_worker_closes_its_five_tools_and_wave_before_terminal_event(): void
    {
        $run = $this->makeRun();
        $other = $this->makeRun('another-tenant');
        $otherTool = $this->tool($other);
        for ($i = 1; $i <= 5; $i++) $this->tool($run, $i);
        $completed = $this->tool($run, 6, 'completed');
        $completedBefore = $completed->fresh()->getAttributes();

        $job = new ExecuteAgentRunJob($run->id, $run->tenant_id);
        $job->failed(new \Illuminate\Queue\MaxAttemptsExceededException('PRIVATE credentials'));

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(5, $run->toolExecutions()->where('status', 'failed')->count());
        $this->assertSame(0, $run->toolExecutions()->where('status', 'running')->count());
        $this->assertSame($completedBefore, $completed->fresh()->getAttributes());
        $this->assertSame('running', $otherTool->fresh()->status);
        $this->assertSame('failed', data_get($run->fresh()->result_json, 'waves.wave-1.status'));
        $this->assertFalse(data_get($run->fresh()->result_json, 'wave_dispatching'));
        $this->assertSame(['tool.failed', 'tool.failed', 'tool.failed', 'tool.failed', 'tool.failed', 'run.failed'],
            $run->events()->orderBy('sequence')->pluck('type')->all());
        $tool = $run->toolExecutions()->where('logical_index', 1)->sole();
        $this->assertNotNull($tool->completed_at);
        $this->assertTrue(data_get($tool->result_meta_json, 'outcome_unknown'));
        $this->assertSame('agent_run_failed', $tool->error_code);
        $this->assertSame('wave-1', data_get($tool->result_meta_json, 'wave_id'));
        $this->assertStringNotContainsString('PRIVATE', $run->events()->get()->toJson());

        $job->failed(new \RuntimeException('second failure'));
        $this->assertCount(6, $run->events()->get());
        $this->assertSame([], app(AgentTerminalExecutionFinalizer::class)->close($run->fresh()));
    }

    public function test_cancellation_closes_open_tools_but_does_not_touch_successful_siblings(): void
    {
        $run = $this->makeRun();
        $pending = $this->tool($run);
        $finished = $this->tool($run, 2, 'completed');
        app(AgentRunControl::class)->cancel($run);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('run_cancelled', $pending->fresh()->error_code);
        $this->assertSame('completed', $finished->fresh()->status);
        $this->assertSame(['tool.failed', 'run.cancelled'], $run->events()->orderBy('sequence')->pluck('type')->all());
    }

    public function test_partial_finalization_and_repair_are_idempotent_and_preserve_the_answer(): void
    {
        $run = $this->makeRun();
        $pending = $this->tool($run);
        $state = [...$run->result_json, 'response' => ['answer' => 'Previously verified finding']];
        $run->forceFill(['status' => 'partial', 'completed_at' => now(), 'result_json' => $state])->save();
        app(AgentEventPublisher::class)->publish($run, 'run.partial', canCancel: false);
        $this->assertSame('failed', $pending->fresh()->status);
        $this->assertSame('run_interrupted', $pending->fresh()->error_code);
        $this->assertSame('Previously verified finding', data_get($run->fresh()->result_json, 'response.answer'));
        $this->assertSame([], app(AgentTerminalExecutionFinalizer::class)->close($run));
    }

    public function test_active_handoffs_are_not_closed_and_late_workers_reject_terminal_runs(): void
    {
        foreach (['running', 'waiting_api_tool', 'awaiting_confirmation', 'awaiting_mcp_input'] as $status) {
            $run = $this->makeRun();
            $run->forceFill(['status' => $status])->save();
            $pending = $this->tool($run);
            $this->assertSame([], app(AgentTerminalExecutionFinalizer::class)->close($run));
            $this->assertSame('running', $pending->fresh()->status);
            app(AgentRunControl::class)->ensureActive($run);
        }
        foreach (['failed', 'completed', 'partial'] as $status) {
            $run = $this->makeRun();
            $stale = clone $run;
            $run->forceFill(['status' => $status])->save();
            try {
                app(AgentRunControl::class)->ensureActive($stale);
                $this->fail('Late work must not change a terminal run.');
            } catch (\DomainException $exception) {
                $this->assertSame('agent_run_not_active', $exception->getMessage());
            }
        }
    }

    public function test_historical_repair_is_scoped_idempotent_and_never_replays_requests(): void
    {
        $run = $this->makeRun();
        $pending = $this->tool($run);
        $run->forceFill(['status' => 'failed', 'error_code' => 'agent_run_failed', 'completed_at' => now()])->save();
        $active = $this->makeRun();
        $activeTool = $this->tool($active);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Queue::fake();
        $this->artisan('agent:reconcile-terminal-run', ['run_id' => $run->run_id])->assertExitCode(2);
        $this->artisan('agent:reconcile-terminal-run', ['run_id' => $run->run_id, '--tenant' => 'wrong'])->assertExitCode(1);
        $this->assertSame('running', $pending->fresh()->status);
        $this->artisan('agent:reconcile-terminal-run', ['run_id' => $active->run_id, '--tenant' => 'acme'])->assertExitCode(1);
        $this->assertSame('running', $activeTool->fresh()->status);

        $this->artisan('agent:reconcile-terminal-run', ['run_id' => $run->run_id, '--tenant' => 'acme'])
            ->expectsOutput('Reconciled terminal run; closed tools: 1.')->assertExitCode(0);
        $this->artisan('agent:reconcile-terminal-run', ['run_id' => $run->run_id, '--tenant' => 'acme'])
            ->expectsOutput('Reconciled terminal run; closed tools: 0.')->assertExitCode(0);
        $this->assertSame('failed', $pending->fresh()->status);
        $this->assertSame(['tool.failed'], $run->events()->pluck('type')->all());
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertFalse(data_get($run->fresh()->result_json, 'wave_dispatching'));
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    private function makeRun(string $tenant = 'acme'): AgentRun
    {
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'project_key' => 'crm',
            'channel' => 'widget', 'actor_type' => 'anonymous_widget', 'locale' => 'it', 'timezone' => 'UTC',
            'status' => 'running', 'started_at' => now()->subSeconds(180),
            'result_json' => ['phase' => 'collection', 'wave_dispatching' => true,
                'waves' => ['wave-1' => ['id' => 'wave-1', 'status' => 'running', 'actions' => ['action-1']]]]]);
    }

    private function tool(AgentRun $run, int $index = 1, string $status = 'running'): \App\Models\AgentToolExecution
    {
        return $run->toolExecutions()->create(['logical_index' => $index, 'tool_name' => 'test_tool', 'tool_kind' => 'api',
            'status' => $status, 'arguments_json' => [], 'started_at' => now()->subSeconds(150),
            'completed_at' => $status === 'completed' ? now()->subMinute() : null,
            'result_meta_json' => ['action_id' => 'action-'.$index, 'wave_id' => 'wave-1', 'physical_quota' => 50]]);
    }
}
