<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CanonicalIndexerJob;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R30 — proves {@see CanonicalIndexerJob::handle()} restores the previous
 * tenant on the {@see TenantContext} singleton on exit (success AND
 * failure paths). Long-lived queue workers drain many jobs per PHP boot
 * sharing the same container singletons; without restore-on-exit, this
 * job's tenant bleeds into the next job's worker context.
 *
 * Closes Copilot PR #115 review iteration 2 finding #4.
 */
final class CanonicalIndexerJobTenantRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_handle_restores_previous_tenant_after_successful_run(): void
    {
        $tenantContext = $this->app->make(TenantContext::class);

        // Pre-existing canonical doc owned by tenant-y.
        $tenantContext->set('tenant-y');
        $doc = $this->seedCanonicalDoc('demo', 'dec-x', 'tenant-y');

        // Worker is on tenant-x.
        $tenantContext->set('tenant-x');

        (new CanonicalIndexerJob($doc->id, 'tenant-y'))->handle();

        $this->assertSame(
            'tenant-x',
            $tenantContext->current(),
            'CanonicalIndexerJob::handle must restore the previous tenant_id on the singleton on exit.',
        );
    }

    public function test_handle_restores_previous_tenant_when_document_not_found_short_circuits(): void
    {
        $tenantContext = $this->app->make(TenantContext::class);
        $tenantContext->set('tenant-x');

        // Document does not exist — handle() returns early after the
        // tenant set. The finally block must still fire.
        (new CanonicalIndexerJob(999999, 'tenant-y'))->handle();

        $this->assertSame(
            'tenant-x',
            $tenantContext->current(),
            'CanonicalIndexerJob::handle must restore the previous tenant_id even on early-return paths (try/finally).',
        );
    }

    private function seedCanonicalDoc(
        string $projectKey,
        string $slug,
        string $tenantId,
    ): KnowledgeDocument {
        return KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => 'Doc',
            'source_path' => "decisions/{$slug}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'doc_id' => 'DEC-0001',
            'slug' => $slug,
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 70,
        ]);
    }
}
