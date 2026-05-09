<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * R30/R31 — proves {@see DocumentIngestor::findExistingVersion()} +
 * {@see DocumentIngestor::persistFromDrafts()} are tenant-scoped on the
 * idempotency lookup so two tenants ingesting identical content under
 * the same `(project_key, source_path)` produce two distinct rows
 * instead of one tenant clobbering the other.
 *
 * Closes the cross-tenant leak Copilot flagged on PR #115 iteration 2,
 * and the pre-existing follow-up hole tracked as task #17.
 */
final class DocumentIngestorCrossTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(static fn () => [0.1, 0.2, 0.3], $texts),
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_same_content_under_two_tenants_yields_two_distinct_documents(): void
    {
        $ingestor = app(DocumentIngestor::class);
        $tenantContext = app(TenantContext::class);

        // Identical bytes — same SHA-256 version_hash. Under the pre-fix
        // behaviour the second ingest would silently return the first
        // tenant's row instead of inserting a new row for tenant-b.
        $markdown = "# Hello\n\nIdentical body across tenants.";

        $tenantContext->set('tenant-a');
        $docA = $ingestor->ingestMarkdown('demo', 'docs/intro.md', 'Intro', $markdown);
        $this->assertSame('tenant-a', $docA->tenant_id);
        $expectedHash = hash('sha256', $markdown);
        $this->assertSame($expectedHash, $docA->version_hash);
        $indexedAtBefore = $docA->indexed_at;

        $tenantContext->set('tenant-b');
        $docB = $ingestor->ingestMarkdown('demo', 'docs/intro.md', 'Intro', $markdown);

        // Two distinct rows, one per tenant — proves R30 isolation.
        $this->assertNotSame($docA->id, $docB->id);
        $this->assertSame('tenant-b', $docB->tenant_id);
        $this->assertSame($expectedHash, $docB->version_hash);

        $this->assertSame(
            1,
            KnowledgeDocument::where('tenant_id', 'tenant-a')
                ->where('project_key', 'demo')
                ->count(),
        );
        $this->assertSame(
            1,
            KnowledgeDocument::where('tenant_id', 'tenant-b')
                ->where('project_key', 'demo')
                ->count(),
        );

        // Tenant A's `indexed_at` must NOT have been bumped by tenant B's
        // ingest. Under the pre-fix behaviour the second ingest would have
        // hit findExistingVersion → existing path → `update(['indexed_at' => now()])`
        // on tenant-a's row.
        $tenantADocReloaded = KnowledgeDocument::find($docA->id);
        $this->assertNotNull($tenantADocReloaded);
        $this->assertEquals(
            $indexedAtBefore?->toDateTimeString(),
            $tenantADocReloaded->indexed_at?->toDateTimeString(),
            "Tenant A's indexed_at must not be bumped by tenant B's ingest of identical content.",
        );
    }

    public function test_re_ingest_under_same_tenant_with_same_content_is_still_idempotent(): void
    {
        // Regression guard: the tenant-aware lookup must NOT break the
        // single-tenant idempotency path that DocumentIngestor relies on.
        $ingestor = app(DocumentIngestor::class);
        app(TenantContext::class)->set('tenant-a');

        $first = $ingestor->ingestMarkdown('demo', 'docs/intro.md', 'Intro', '# Hello');
        $second = $ingestor->ingestMarkdown('demo', 'docs/intro.md', 'Intro', '# Hello');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, KnowledgeDocument::where('tenant_id', 'tenant-a')->count());
    }
}
