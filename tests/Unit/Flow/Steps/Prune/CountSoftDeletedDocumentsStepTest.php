<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Prune;

use App\Flow\Steps\Prune\CountSoftDeletedDocumentsStep;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use RuntimeException;
use Tests\TestCase;

final class CountSoftDeletedDocumentsStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_counts_only_soft_deleted_older_than_cutoff(): void
    {
        $this->seedDoc('tenant-a', 'p', 'old.md', deletedDaysAgo: 60);
        $this->seedDoc('tenant-a', 'p', 'fresh.md', deletedDaysAgo: 5);
        $this->seedDoc('tenant-a', 'p', 'live.md', deletedDaysAgo: null);

        $step = $this->app->make(CountSoftDeletedDocumentsStep::class);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $result = $step->execute($this->context('tenant-a', $cutoff->toIso8601String()));

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->output['planned_count']);
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(CountSoftDeletedDocumentsStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-deleted',
            input: ['cutoff_iso' => CarbonImmutable::now()->toIso8601String()],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_throws_on_invalid_cutoff(): void
    {
        $step = $this->app->make(CountSoftDeletedDocumentsStep::class);
        $context = $this->context('tenant-a', 'not-a-date');

        $this->expectException(RuntimeException::class);
        $step->execute($context);
    }

    public function test_tenant_isolation_does_not_count_other_tenants_rows(): void
    {
        $this->seedDoc('tenant-a', 'p', 'old.md', deletedDaysAgo: 60);
        $this->seedDoc('tenant-b', 'p', 'old-b.md', deletedDaysAgo: 60);

        $step = $this->app->make(CountSoftDeletedDocumentsStep::class);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $result = $step->execute($this->context('tenant-a', $cutoff->toIso8601String()));

        $this->assertSame(1, $result->output['planned_count']);
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

    private function seedDoc(string $tenantId, string $projectKey, string $sourcePath, ?int $deletedDaysAgo): KnowledgeDocument
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
        if ($deletedDaysAgo !== null) {
            $doc->deleted_at = CarbonImmutable::now()->subDays($deletedDaysAgo);
            $doc->save();
        }
        return $doc;
    }
}
