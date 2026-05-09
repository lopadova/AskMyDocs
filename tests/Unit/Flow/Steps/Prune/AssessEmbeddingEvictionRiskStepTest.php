<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Prune;

use App\Flow\Steps\Prune\AssessEmbeddingEvictionRiskStep;
use App\Support\TenantContext;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class AssessEmbeddingEvictionRiskStepTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_auto_resolves_when_planned_under_threshold(): void
    {
        config()->set('kb.embedding_cache.approval_threshold', 5000);
        $step = $this->app->make(AssessEmbeddingEvictionRiskStep::class);

        $result = $step->execute($this->context('default', planned: 100));

        $this->assertTrue($result->success);
        $this->assertFalse($result->paused);
        $this->assertFalse($result->output['approval_required']);
    }

    public function test_pauses_when_planned_over_threshold(): void
    {
        config()->set('kb.embedding_cache.approval_threshold', 5000);
        $step = $this->app->make(AssessEmbeddingEvictionRiskStep::class);

        $result = $step->execute($this->context('default', planned: 9001));

        $this->assertTrue($result->paused);
        $this->assertTrue($result->output['approval_required']);
        $this->assertSame(5000, $result->output['threshold']);
        $this->assertSame(9001, $result->output['planned_count']);
    }

    public function test_dry_run_auto_resolves_even_over_threshold(): void
    {
        config()->set('kb.embedding_cache.approval_threshold', 100);
        $step = $this->app->make(AssessEmbeddingEvictionRiskStep::class);

        $result = $step->execute($this->context('default', planned: 9001, dryRun: true));

        $this->assertTrue($result->success);
        $this->assertFalse($result->paused);
        $this->assertFalse($result->output['approval_required']);
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(AssessEmbeddingEvictionRiskStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-embedding-cache',
            input: [],
            stepOutputs: ['count-stale-embeddings' => ['planned_count' => 1]],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    private function context(string $tenantId, int $planned, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-embedding-cache',
            input: ['tenant_id' => $tenantId],
            stepOutputs: ['count-stale-embeddings' => ['planned_count' => $planned]],
            dryRun: $dryRun,
        );
    }
}
