<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Folder;

use App\Flow\Steps\Folder\DispatchIngestFanOutStep;
use App\Jobs\IngestDocumentJob;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class DispatchIngestFanOutStepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_dispatches_one_job_per_file(): void
    {
        Queue::fake();
        Storage::disk('kb')->put('docs/a.md', '# a');
        Storage::disk('kb')->put('docs/b.md', '# b');

        $step = $this->app->make(DispatchIngestFanOutStep::class);
        $result = $step->execute($this->context(['docs/a.md', 'docs/b.md']));

        $this->assertSame(2, $result->output['dispatched_count']);
        $this->assertSame(0, $result->output['failure_count']);
        Queue::assertPushed(IngestDocumentJob::class, 2);
    }

    public function test_unsupported_extension_recorded_as_failure_not_thrown(): void
    {
        Queue::fake();
        $step = $this->app->make(DispatchIngestFanOutStep::class);

        $result = $step->execute($this->context(['docs/a.md', 'docs/b.png']));

        $this->assertSame(1, $result->output['dispatched_count']);
        $this->assertSame(1, $result->output['failure_count']);
    }

    public function test_invalid_path_recorded_as_failure_not_thrown(): void
    {
        Queue::fake();
        $step = $this->app->make(DispatchIngestFanOutStep::class);

        // KbPath::normalize() rejects '..' traversal segments — this MUST
        // surface as a per-file failure so the rest of the batch keeps
        // dispatching.
        $result = $step->execute($this->context(['docs/../escape.md', 'docs/ok.md']));

        $this->assertSame(1, $result->output['dispatched_count']);
        $this->assertSame(1, $result->output['failure_count']);
    }

    public function test_dry_run_skipped(): void
    {
        Queue::fake();
        $step = $this->app->make(DispatchIngestFanOutStep::class);

        $result = $step->execute($this->context(['docs/a.md'], dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        Queue::assertNothingPushed();
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(DispatchIngestFanOutStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: ['project_key' => 'p'],
            stepOutputs: ['list-files' => ['disk' => 'kb', 'matched_files' => []]],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_throws_on_missing_project_key(): void
    {
        Queue::fake();
        $step = $this->app->make(DispatchIngestFanOutStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: ['tenant_id' => 'default'],
            stepOutputs: ['list-files' => ['disk' => 'kb', 'matched_files' => ['a.md']]],
        );

        $this->expectException(\RuntimeException::class);
        $step->execute($context);
    }

    public function test_dispatch_carries_tenant_id_into_job(): void
    {
        Queue::fake();
        Storage::disk('kb')->put('docs/a.md', '# a');

        $step = $this->app->make(DispatchIngestFanOutStep::class);
        $step->execute($this->context(['docs/a.md'], tenantId: 'tenant-x'));

        Queue::assertPushed(IngestDocumentJob::class, fn ($job): bool => $job->tenantId === 'tenant-x');
    }

    /**
     * @param  list<string>  $files
     */
    private function context(array $files, string $tenantId = 'default', bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: [
                'tenant_id' => $tenantId,
                'project_key' => 'p',
                'sync' => false,
                'prefix' => '',
            ],
            stepOutputs: [
                'list-files' => [
                    'disk' => 'kb',
                    'matched_files' => $files,
                ],
            ],
            dryRun: $dryRun,
        );
    }
}
