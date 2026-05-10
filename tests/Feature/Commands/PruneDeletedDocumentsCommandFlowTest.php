<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage for the v4.2 Flow refactor of `kb:prune-deleted`.
 *
 * Mirrors the legacy {@see PruneDeletedDocumentsCommandTest} fixture
 * style but exercises the Flow path (NOT mocking Flow::execute) so any
 * regression in the StepTenantBinder / Flow registration / engine
 * idempotency wiring surfaces here.
 */
final class PruneDeletedDocumentsCommandFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_fans_out_per_tenant_and_reports_per_tenant_count(): void
    {
        $this->seedSoftDeleted('tenant-a', 'pa', 'a.md', daysAgo: 60);
        $this->seedSoftDeleted('tenant-b', 'pb', 'b.md', daysAgo: 60);

        $this->artisan('kb:prune-deleted', ['--days' => 30])
            ->expectsOutputToContain('[tenant-a] Pruned 1 soft-deleted document(s)')
            ->expectsOutputToContain('[tenant-b] Pruned 1 soft-deleted document(s)')
            ->assertSuccessful();

        $this->assertSame(0, KnowledgeDocument::withTrashed()->count());
    }

    public function test_tenant_filter_restricts_to_one_tenant(): void
    {
        $a = $this->seedSoftDeleted('tenant-a', 'pa', 'a.md', daysAgo: 60);
        $b = $this->seedSoftDeleted('tenant-b', 'pb', 'b.md', daysAgo: 60);

        $this->artisan('kb:prune-deleted', ['--days' => 30, '--tenant' => 'tenant-a'])
            ->assertSuccessful();

        $this->assertNull(KnowledgeDocument::withTrashed()->find($a->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($b->id));
    }

    public function test_dry_run_does_not_delete(): void
    {
        $doc = $this->seedSoftDeleted('default', 'p', 'a.md', daysAgo: 60);

        $this->artisan('kb:prune-deleted', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('Would prune 1')
            ->assertSuccessful();

        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($doc->id));
    }

    public function test_zero_retention_is_a_noop(): void
    {
        $this->seedSoftDeleted('default', 'p', 'a.md', daysAgo: 60);

        $this->artisan('kb:prune-deleted', ['--days' => 0])
            ->expectsOutputToContain('skipping prune')
            ->assertSuccessful();
    }

    public function test_no_eligible_rows_short_circuits(): void
    {
        $this->artisan('kb:prune-deleted', ['--days' => 30])
            ->expectsOutputToContain('No tenants have soft-deleted')
            ->assertSuccessful();
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
