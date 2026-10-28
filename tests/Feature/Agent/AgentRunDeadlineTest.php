<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentRunDeadline;
use App\Models\AgentDiagnosticSpan;
use App\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AgentRunDeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.job_timeout_seconds' => 120, 'agent.finalization.safety_seconds' => 5]);
        $this->travelTo(now()->startOfSecond());
    }

    public function test_remaining_and_boundary_with_no_worker_span(): void
    {
        $run = $this->runRecord();
        $deadline = app(AgentRunDeadline::class);
        $this->assertSame(INF, $deadline->remaining(null));
        $this->assertFalse($deadline->skip(null, 'test', 10));
        $this->travel(100)->seconds();
        $this->assertEquals(15, $deadline->remaining($run));
        $this->assertTrue($deadline->canStart($run, 15));
        $this->assertFalse($deadline->canStart($run, 15.1));
        $this->assertTrue($deadline->skip($run, 'synthesis', 16));
        $span = AgentDiagnosticSpan::query()->where('phase', 'finalization.skipped')->sole();
        $this->assertSame('completed', $span->status);
        $this->assertEquals(['operation' => 'synthesis', 'reason' => 'deadline', 'remaining_seconds' => 15, 'required_seconds' => 16], $span->metadata_json);
    }

    public function test_latest_worker_start_and_recorded_timeout_override_run_and_configuration(): void
    {
        $run = $this->runRecord();
        $this->travel(80)->seconds();
        $this->worker($run, now()->subSeconds(50), 120);
        $this->worker($run, now(), 180);
        config(['agent.job_timeout_seconds' => 30]);
        $this->travel(20)->seconds();
        $this->assertEquals(155, app(AgentRunDeadline::class)->remaining($run));
    }

    public function test_worker_lookup_is_tenant_scoped_and_future_start_is_clamped(): void
    {
        $run = $this->runRecord();
        $this->worker($run, now()->subSeconds(40), 120);
        $this->worker($run, now()->addSeconds(10), 300, 'other');
        $this->assertEquals(75, app(AgentRunDeadline::class)->remaining($run));
        $this->worker($run, now()->addSeconds(5), 120);
        $this->assertEquals(115, app(AgentRunDeadline::class)->remaining($run));
    }

    public function test_active_worker_budget_ignores_duplicate_span_and_restores_after_exception(): void
    {
        $run = $this->runRecord();
        $deadline = app(AgentRunDeadline::class);
        $started = now();
        try {
            $deadline->within($run, $started, 120, function () use ($run, $deadline): void {
                $this->travel(100)->seconds();
                $this->worker($run, now(), 120);
                $this->assertEquals(15, $deadline->remaining($run));
                $deadline->within($run, now(), 120, function () use ($run, $deadline): void {
                    $this->assertEquals(115, $deadline->remaining($run));
                });
                $this->assertEquals(15, $deadline->remaining($run));
                throw new \RuntimeException('test');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('test', $exception->getMessage());
        }
        $this->assertEquals(115, $deadline->remaining($run));
    }

    private function runRecord(): AgentRun
    {
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'crm',
            'channel' => 'widget', 'actor_type' => 'anonymous_widget', 'locale' => 'it', 'timezone' => 'UTC', 'status' => 'queued']);
    }

    private function worker(AgentRun $run, $start, int $timeout, string $tenant = 'acme'): void
    {
        AgentDiagnosticSpan::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'agent_run_id' => $run->id,
            'phase' => 'worker.execute', 'status' => 'started', 'started_at' => $start,
            'metadata_json' => ['timeout_seconds' => $timeout]]);
    }
}
