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

    public function test_worker_timeout_hook_records_observed_timeout_without_changing_run_status(): void
    {
        $run = $this->runRecord();
        (new ExecuteAgentRunJob($run->id, $run->tenant_id))->failed(new \Illuminate\Queue\TimeoutExceededException('Authorization: Bearer hidden'));
        $span = AgentDiagnosticSpan::query()->where('phase', 'worker.failed')->sole();
        $this->assertSame('timeout', $span->metadata_json['error_kind']);
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertStringNotContainsString('hidden', $span->toJson());
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
