<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Promotion;

use App\Flow\Steps\Promotion\DispatchPromotionIngestStep;
use App\Jobs\IngestDocumentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Padosoft\LaravelFlow\FlowContext;
use RuntimeException;
use Tests\TestCase;

final class DispatchPromotionIngestStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_ingest_job_with_canonical_path(): void
    {
        Queue::fake();
        $step = $this->app->make(DispatchPromotionIngestStep::class);

        $result = $step->execute($this->context());

        $this->assertTrue($result->success);
        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return $job->relativePath === 'decisions/dec-x.md'
                && $job->projectKey === 'acme'
                && $job->disk === 'kb';
        });
    }

    public function test_dry_run_skipped(): void
    {
        Queue::fake();
        $step = $this->app->make(DispatchPromotionIngestStep::class);

        $result = $step->execute($this->context(dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        Queue::assertNothingPushed();
    }

    public function test_throws_on_missing_write_step_output(): void
    {
        $step = $this->app->make(DispatchPromotionIngestStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.promote',
            input: ['tenant_id' => 'default'],
            stepOutputs: [],
            dryRun: false,
        );

        $this->expectException(RuntimeException::class);
        $step->execute($context);
    }

    private function context(bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'dispatch-test',
            definitionName: 'kb.promote',
            input: ['tenant_id' => 'default', 'title' => 'X'],
            stepOutputs: [
                'write-markdown' => [
                    'project_key' => 'acme',
                    'relative_path' => 'decisions/dec-x.md',
                    'disk' => 'kb',
                    'slug' => 'dec-x',
                    'doc_id' => 'DEC-0001',
                ],
            ],
            dryRun: $dryRun,
        );
    }
}
