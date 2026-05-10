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

    public function test_threshold_zero_disables_gate_even_for_huge_planned_count(): void
    {
        // Copilot iter 1 finding (PR #117). config docs say
        // "set to 0 (or negative) to disable the gate", but the previous
        // implementation paused whenever `planned > threshold` — so
        // threshold=0 with any non-zero planned count would always pause,
        // turning the disable knob into an always-on knob. Guard the
        // short-circuit at the top of the step.
        config()->set('kb.embedding_cache.approval_threshold', 0);
        $step = $this->app->make(AssessEmbeddingEvictionRiskStep::class);

        $result = $step->execute($this->context('default', planned: 10000));

        $this->assertTrue($result->success);
        $this->assertFalse($result->paused);
        $this->assertFalse($result->output['approval_required']);
        $this->assertTrue($result->output['gate_disabled']);
        $this->assertSame(10000, $result->output['planned_count']);
        $this->assertSame(0, $result->output['threshold']);
    }

    public function test_negative_threshold_also_disables_gate(): void
    {
        // Same fix — defensive against negative envs.
        config()->set('kb.embedding_cache.approval_threshold', -1);
        $step = $this->app->make(AssessEmbeddingEvictionRiskStep::class);

        $result = $step->execute($this->context('default', planned: 10000));

        $this->assertTrue($result->success);
        $this->assertFalse($result->paused);
        $this->assertFalse($result->output['approval_required']);
        $this->assertTrue($result->output['gate_disabled']);
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
