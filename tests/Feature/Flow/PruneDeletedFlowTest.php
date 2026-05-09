<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Flow\Definitions\PruneDeletedFlow;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Tests\TestCase;

final class PruneDeletedFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_hard_deletes_only_soft_rows_for_target_tenant(): void
    {
        $cutoffDays = 30;
        $a = $this->seedSoftDeleted('tenant-a', 'pa', 'old.md', daysAgo: 60);
        $b = $this->seedSoftDeleted('tenant-b', 'pb', 'old.md', daysAgo: 60);

        $cutoff = CarbonImmutable::now()->subDays($cutoffDays);
        $tc = $this->app->make(TenantContext::class);
        $tc->set('tenant-a');

        $run = Flow::execute(
            PruneDeletedFlow::NAME,
            ['tenant_id' => 'tenant-a', 'cutoff_iso' => $cutoff->toIso8601String()],
            FlowExecutionOptions::make(correlationId: 'tenant-a'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertNull(KnowledgeDocument::withTrashed()->find($a->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($b->id), 'tenant-b row preserved');
    }

    public function test_dry_run_records_plan_without_deleting(): void
    {
        $cutoff = CarbonImmutable::now()->subDays(30);
        $doc = $this->seedSoftDeleted('default', 'p', 'old.md', daysAgo: 60);

        $run = Flow::dryRun(
            PruneDeletedFlow::NAME,
            ['tenant_id' => 'default', 'cutoff_iso' => $cutoff->toIso8601String()],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($doc->id));
        $countResult = $run->stepResults['count-soft-deleted'];
        $this->assertSame(1, $countResult->output['planned_count']);
    }

    public function test_skips_rows_within_retention(): void
    {
        $fresh = $this->seedSoftDeleted('default', 'p', 'fresh.md', daysAgo: 5);
        $cutoff = CarbonImmutable::now()->subDays(30);

        $run = Flow::execute(
            PruneDeletedFlow::NAME,
            ['tenant_id' => 'default', 'cutoff_iso' => $cutoff->toIso8601String()],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($fresh->id));
    }

    private function seedSoftDeleted(string $tenantId, string $projectKey, string $sourcePath, int $daysAgo): KnowledgeDocument
    {
        $tc = $this->app->make(TenantContext::class);
        $tc->set($tenantId);
        $doc = KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => 'X',
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => hash('sha256', $tenantId.$sourcePath.'doc'),
            'version_hash' => hash('sha256', $tenantId.$sourcePath.'ver'),
            'metadata' => null,
        ]);
        $doc->deleted_at = CarbonImmutable::now()->subDays($daysAgo);
        $doc->save();
        return $doc;
    }
}
