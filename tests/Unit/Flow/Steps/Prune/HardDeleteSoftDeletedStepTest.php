<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Prune;

use App\Flow\Steps\Prune\HardDeleteSoftDeletedStep;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class HardDeleteSoftDeletedStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_hard_deletes_only_soft_rows_older_than_cutoff(): void
    {
        $old = $this->seedDoc('tenant-a', 'old.md', 60);
        $fresh = $this->seedDoc('tenant-a', 'fresh.md', 5);

        $step = $this->app->make(HardDeleteSoftDeletedStep::class);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $result = $step->execute($this->context('tenant-a', $cutoff->toIso8601String()));

        $this->assertSame(1, $result->output['deleted_count']);
        $this->assertNull(KnowledgeDocument::withTrashed()->find($old->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($fresh->id));
    }

    public function test_dry_run_skipped(): void
    {
        $doc = $this->seedDoc('tenant-a', 'old.md', 60);
        $step = $this->app->make(HardDeleteSoftDeletedStep::class);

        $cutoff = CarbonImmutable::now()->subDays(30);
        $result = $step->execute($this->context('tenant-a', $cutoff->toIso8601String(), dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($doc->id));
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(HardDeleteSoftDeletedStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-deleted',
            input: ['cutoff_iso' => CarbonImmutable::now()->toIso8601String()],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_tenant_isolation_does_not_delete_other_tenants_rows(): void
    {
        $a = $this->seedDoc('tenant-a', 'old.md', 60);
        $b = $this->seedDoc('tenant-b', 'old.md', 60);

        $step = $this->app->make(HardDeleteSoftDeletedStep::class);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $step->execute($this->context('tenant-a', $cutoff->toIso8601String()));

        $this->assertNull(KnowledgeDocument::withTrashed()->find($a->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($b->id));
    }

    private function context(string $tenantId, string $cutoffIso, bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-deleted',
            input: ['tenant_id' => $tenantId, 'cutoff_iso' => $cutoffIso],
            dryRun: $dryRun,
        );
    }

    private function seedDoc(string $tenantId, string $sourcePath, int $deletedDaysAgo): KnowledgeDocument
    {
        $tc = $this->app->make(TenantContext::class);
        $tc->set($tenantId);
        $doc = KnowledgeDocument::create([
            'project_key' => 'p',
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
        $doc->deleted_at = CarbonImmutable::now()->subDays($deletedDaysAgo);
        $doc->save();
        return $doc;
    }
}
