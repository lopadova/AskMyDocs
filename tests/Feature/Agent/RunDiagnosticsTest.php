<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentRunCancelledException;
use App\Agent\AgentRunDispatcher;
use App\Agent\Debug\RunDiagnostics;
use App\Contracts\AgentRunHandler;
use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentDiagnosticSpan;
use App\Models\AgentRun;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RunDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_begin_is_durable_while_callback_is_pending_and_end_is_idempotent(): void
    {
        $run = $this->runRecord();
        $diagnostics = app(RunDiagnostics::class);
        $result = $diagnostics->measure($run, 'kb.semantic_lookup', function () use ($run) {
            $open = AgentDiagnosticSpan::query()->where('agent_run_id', $run->id)->sole();
            $this->assertSame('started', $open->status);
            $this->assertNull($open->ended_at);
            return 'unchanged';
        }, ['wait_for' => 'embedding_response', 'query' => 'PRIVATE', 'password' => 'SECRET']);
        $span = AgentDiagnosticSpan::query()->sole();
        $this->assertSame('unchanged', $result);
        $this->assertSame('completed', $span->status);
        $this->assertGreaterThanOrEqual(0, $span->duration_ms);
        $this->assertArrayNotHasKey('query', $span->metadata_json);
        $this->assertArrayNotHasKey('password', $span->metadata_json);
        $diagnostics->end($run, $span->id, 'failed');
        $this->assertSame('completed', $span->fresh()->status);
    }

    public function test_failure_and_cancellation_keep_original_exception_without_message_or_trace(): void
    {
        $run = $this->runRecord();
        foreach ([new \RuntimeException('password=secret-123 user@example.test'), new AgentRunCancelledException($run->run_id)] as $exception) {
            try {
                app(RunDiagnostics::class)->measure($run, 'provider.request', fn () => throw $exception);
                $this->fail('Expected original exception.');
            } catch (\Throwable $caught) {
                $this->assertSame($exception, $caught);
            }
        }
        $spans = AgentDiagnosticSpan::query()->orderBy('started_at')->get();
        $this->assertSame(['failed', 'cancelled'], $spans->pluck('status')->all());
        $this->assertSame('exception', $spans[0]->metadata_json['error_kind']);
        $this->assertSame('cancelled', $spans[1]->metadata_json['error_kind']);
        $this->assertStringNotContainsString('secret-123', $spans->toJson());
        $this->assertStringNotContainsString('user@example.test', $spans->toJson());
    }

    public function test_cap_and_tenant_boundary_are_enforced_without_changing_callback(): void
    {
        $run = $this->runRecord();
        $other = $this->runRecord('other');
        $diagnostics = app(RunDiagnostics::class);
        $parent = $diagnostics->begin($other, 'research.branch');
        $id = $diagnostics->begin($run, 'kb.lookup', ['model' => str_repeat('x', 20000), 'runtime' => ['password' => 'secret']], $parent);
        $this->assertNull(AgentDiagnosticSpan::findOrFail($id)->parent_span_id);
        $diagnostics->end($other, $id, 'failed');
        $this->assertSame('started', AgentDiagnosticSpan::findOrFail($id)->status);
        for ($i = 1; $i < RunDiagnostics::MAX_SPANS + 4; $i++) {
            $diagnostics->measure($run, 'kb.lookup', static fn () => null);
        }
        $this->assertSame(RunDiagnostics::MAX_SPANS, AgentDiagnosticSpan::query()->where('agent_run_id', $run->id)->count());
        $this->assertSame('result', $diagnostics->measure($run, 'kb.lookup', static fn () => 'result'));
        $this->assertLessThanOrEqual(RunDiagnostics::MAX_METADATA_BYTES, strlen(json_encode(AgentDiagnosticSpan::findOrFail($id)->metadata_json)));
        $this->assertStringNotContainsString('password', json_encode(AgentDiagnosticSpan::findOrFail($id)->metadata_json));
    }

    public function test_missing_migration_fails_open_and_observer_invokes_callback_exactly_once(): void
    {
        $run = $this->runRecord();
        Schema::drop('agent_diagnostic_spans');
        $calls = 0;
        $observer = app(RunDiagnostics::class)->observer($run);
        $this->assertSame(42, $observer('kb.lookup', function () use (&$calls) { $calls++; return 42; }));
        $this->assertSame(1, $calls);
        $this->assertFalse(app(RunDiagnostics::class)->available());
    }

    public function test_metadata_limit_keeps_truncation_marker_and_failure_classification(): void
    {
        $run = $this->runRecord();
        $metadata = array_fill_keys(['tool_kind', 'tool_name', 'logical_index', 'iteration', 'operation', 'provider', 'model',
            'wait_reason', 'wait_for', 'child_run_id', 'research_flow_id', 'driver', 'job_id', 'queue_connection', 'queue_name'], str_repeat('x', 160));
        $id = app(RunDiagnostics::class)->begin($run, 'provider.request', $metadata);
        app(RunDiagnostics::class)->failure($run, $id, new \RuntimeException('PRIVATE'), ['error_handled' => true]);
        $safe = AgentDiagnosticSpan::findOrFail($id)->metadata_json;
        $this->assertTrue($safe['_truncated']);
        $this->assertSame(\RuntimeException::class, $safe['error_class']);
        $this->assertSame('exception', $safe['error_kind']);
        $this->assertTrue($safe['error_handled']);
        $this->assertLessThanOrEqual(RunDiagnostics::MAX_METADATA_BYTES, strlen(json_encode($safe)));
    }

    public function test_diagnostic_end_failure_does_not_escape_or_leak_a_transaction(): void
    {
        $run = $this->runRecord();
        $diagnostics = app(RunDiagnostics::class);
        $id = $diagnostics->begin($run, 'kb.lookup');
        DB::transaction(function () use ($run, $diagnostics, $id): void {
            $level = DB::transactionLevel();
            Schema::drop('agent_diagnostic_spans');
            $diagnostics->end($run, $id);
            $this->assertSame($level, DB::transactionLevel());
            $run->forceFill(['status' => AgentRun::STATUS_RUNNING])->save();
        });
        $this->assertSame(AgentRun::STATUS_RUNNING, $run->fresh()->status);
    }

    public function test_queue_wait_closes_only_on_worker_receipt_and_worker_runtime_is_recorded(): void
    {
        Queue::fake();
        $run = $this->runRecord();
        app(AgentRunDispatcher::class)->enqueue($run);
        $wait = AgentDiagnosticSpan::query()->where('phase', 'queue.wait')->sole();
        $this->assertSame('started', $wait->status);
        Queue::assertPushed(ExecuteAgentRunJob::class, function (ExecuteAgentRunJob $job) use ($run, $wait): bool {
            $this->assertSame($wait->id, $job->diagnosticQueueSpanId);
            $handler = new class implements AgentRunHandler {
                public function handle(AgentRun $run): void {}
            };
            $job->handle($handler, app(TenantContext::class), app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class));
            return $job->agentRunId === $run->id;
        });
        $this->assertSame('completed', $wait->fresh()->status);
        $worker = AgentDiagnosticSpan::query()->where('phase', 'worker.execute')->sole();
        $this->assertSame('completed', $worker->status);
        $this->assertSame(PHP_VERSION, $worker->metadata_json['runtime']['php_version']);
        $this->assertSame('not_probed', $worker->metadata_json['runtime']['worker_liveness']);
    }

    public function test_worker_timeout_hook_records_timeout_and_publishes_a_terminal_failure(): void
    {
        $run = $this->runRecord();
        $run->forceFill(['status' => AgentRun::STATUS_RUNNING])->save();
        (new ExecuteAgentRunJob($run->id, $run->tenant_id))->failed(new \Illuminate\Queue\TimeoutExceededException('Authorization: Bearer hidden'));
        $span = AgentDiagnosticSpan::query()->where('phase', 'worker.failed')->sole();
        $this->assertSame('timeout', $span->metadata_json['error_kind']);
        $this->assertSame(AgentRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('worker_timeout', $run->fresh()->error_code);
        $this->assertNotNull($run->fresh()->completed_at);
        $event = $run->events()->sole();
        $this->assertSame('run.failed', $event->type);
        $this->assertSame(['error_code' => 'worker_timeout'], $event->payload_json['data']);
        $this->assertFalse($event->payload_json['can_cancel']);
        $this->assertStringNotContainsString('hidden', $span->toJson());
        $this->assertStringNotContainsString('hidden', $event->toJson());
    }

    public function test_exhausted_queue_attempts_finalize_the_run_without_claiming_a_worker_timeout(): void
    {
        $run = $this->runRecord();
        $run->forceFill(['status' => AgentRun::STATUS_RUNNING])->save();
        $exception = new \Illuminate\Queue\MaxAttemptsExceededException('PRIVATE timeout Authorization: Bearer hidden');
        $job = new ExecuteAgentRunJob($run->id, $run->tenant_id);

        $job->failed($exception);

        $span = AgentDiagnosticSpan::query()->where('phase', 'worker.failed')->sole();
        $this->assertSame(\Illuminate\Queue\MaxAttemptsExceededException::class, $span->metadata_json['error_class']);
        $this->assertSame('exception', $span->metadata_json['error_kind']);
        $this->assertSame(AgentRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('agent_run_failed', $run->fresh()->error_code);
        $this->assertNotNull($run->fresh()->completed_at);
        $event = $run->events()->sole();
        $this->assertSame('run.failed', $event->type);
        $this->assertSame(['error_code' => 'agent_run_failed'], $event->payload_json['data']);
        $this->assertFalse($event->payload_json['can_cancel']);

        $job->failed($exception);

        $this->assertSame($event->id, $run->events()->sole()->id);
        $this->assertSame(1, $run->fresh()->last_sequence);
        $this->assertStringNotContainsString('PRIVATE', AgentDiagnosticSpan::query()->get()->toJson());
        $this->assertStringNotContainsString('hidden', AgentDiagnosticSpan::query()->get()->toJson());
        $this->assertStringNotContainsString('PRIVATE', $event->toJson());
        $this->assertStringNotContainsString('hidden', $event->toJson());
    }

    public function test_worker_failure_finalizes_queued_and_running_runs_even_without_an_exception(): void
    {
        foreach ([AgentRun::STATUS_QUEUED, AgentRun::STATUS_RUNNING] as $status) {
            foreach ([null, new \RuntimeException('PRIVATE worker failure')] as $exception) {
                $run = $this->runRecord();
                $run->forceFill(['status' => $status])->save();

                (new ExecuteAgentRunJob($run->id, $run->tenant_id))->failed($exception);

                $this->assertSame(AgentRun::STATUS_FAILED, $run->fresh()->status);
                $this->assertSame('agent_run_failed', $run->fresh()->error_code);
                $this->assertNotNull($run->fresh()->completed_at);
                $event = $run->events()->sole();
                $this->assertSame('run.failed', $event->type);
                $this->assertSame(['error_code' => 'agent_run_failed'], $event->payload_json['data']);
                $this->assertStringNotContainsString('PRIVATE', $event->toJson());
            }
        }
        $this->assertStringNotContainsString('PRIVATE', AgentDiagnosticSpan::query()->get()->toJson());
    }

    public function test_repeated_worker_failures_preserve_the_first_terminal_result_and_event(): void
    {
        $run = $this->runRecord();
        $job = new ExecuteAgentRunJob($run->id, $run->tenant_id);
        $job->failed(new \RuntimeException('PRIVATE'));
        $completedAt = $run->fresh()->completed_at;
        $eventId = $run->events()->sole()->id;

        $this->travel(10)->seconds();
        $job->failed(new \Illuminate\Queue\TimeoutExceededException('PRIVATE timeout'));

        $this->assertSame('agent_run_failed', $run->fresh()->error_code);
        $this->assertTrue($completedAt->equalTo($run->fresh()->completed_at));
        $this->assertSame($eventId, $run->events()->sole()->id);
        $this->assertSame(1, $run->fresh()->last_sequence);
    }

    public function test_worker_failure_preserves_terminal_runs_and_interaction_handoffs(): void
    {
        foreach ([AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL, AgentRun::STATUS_FAILED,
            AgentRun::STATUS_CANCELLED, AgentRun::STATUS_WAITING_CLIENT_TOOL, AgentRun::STATUS_AWAITING_CONFIRMATION,
            AgentRun::STATUS_AWAITING_MCP_CONFIRMATION, AgentRun::STATUS_AWAITING_MCP_INPUT, AgentRun::STATUS_WAITING_MCP_TASK] as $status) {
            $run = $this->runRecord();
            $run->forceFill(['status' => $status, 'error_code' => 'existing_result',
                'result_json' => ['checkpoint' => true], 'completed_at' => now()->subMinute()])->save();
            $before = $run->fresh()->getAttributes();

            (new ExecuteAgentRunJob($run->id, $run->tenant_id))->failed(new \Illuminate\Queue\TimeoutExceededException('PRIVATE'));

            $this->assertSame($before, $run->fresh()->getAttributes());
            $this->assertSame(0, $run->events()->count());
        }
    }

    public function test_worker_failure_finalizes_without_diagnostics_and_respects_the_job_tenant(): void
    {
        $run = $this->runRecord();
        Schema::drop('agent_diagnostic_spans');
        (new ExecuteAgentRunJob($run->id, 'another-tenant'))->failed(new \RuntimeException('PRIVATE'));
        $this->assertSame(AgentRun::STATUS_QUEUED, $run->fresh()->status);
        $this->assertSame(0, $run->events()->count());

        (new ExecuteAgentRunJob($run->id, $run->tenant_id))->failed(new \RuntimeException('PRIVATE'));

        $this->assertSame(AgentRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('agent_run_failed', $run->fresh()->error_code);
        $this->assertSame('run.failed', $run->events()->sole()->type);
    }

    private function runRecord(string $tenant = 'acme'): AgentRun
    {
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'project_key' => 'crm',
            'channel' => 'widget', 'actor_type' => 'anonymous_widget', 'locale' => 'it', 'timezone' => 'UTC', 'status' => 'queued']);
    }

    public function test_stream_records_server_emission_separately_and_reconnects_are_bounded(): void
    {
        $run = $this->runRecord();
        $run->forceFill(['status' => AgentRun::STATUS_COMPLETED])->save();
        $event = app(\App\Agent\AgentEventPublisher::class)->publish($run, 'run.completed', canCancel: false);
        for ($i = 0; $i < 10; $i++) {
            $frames = iterator_to_array(app(\App\Agent\AgentEventStream::class)->frames($run, 0));
            $this->assertCount(1, $frames);
        }
        $spans = AgentDiagnosticSpan::query()->where('phase', 'transport.event_stream')->get();
        $this->assertCount(8, $spans);
        $this->assertSame('terminal_log_drained', $spans->first()->metadata_json['operation']);
        $this->assertSame($event->sequence, $spans->first()->metadata_json['last_sequence']);
        $this->assertSame(1, $spans->first()->metadata_json['result_count']);
        $this->assertSame('completed', $spans->first()->status);
        $this->assertSame(1, $run->events()->count());
    }
}
