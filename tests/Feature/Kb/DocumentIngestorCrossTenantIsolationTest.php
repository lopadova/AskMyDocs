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
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
        Mockery::close();
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

    /**
     * R30/R31 — the lookup above is only isolation if the DATABASE agrees.
     * With `uq_kb_doc_version (project_key, source_path, version_hash)` the
     * tenant-scoped lookup finds no row for the second tenant and the insert
     * then dies on a unique keyed on columns the query never filtered by, so
     * the isolation would be advertised and unusable. 2026_10_02_000011
     * rebuilt all three composite uniques to START with `tenant_id`; this
     * asserts the schema, so a later migration cannot narrow them back
     * without a red test.
     */
    public function test_the_composite_uniques_are_keyed_on_tenant_id_first(): void
    {
        $uniques = [];
        foreach (\Illuminate\Support\Facades\Schema::getIndexes('knowledge_documents') as $index) {
            if (($index['unique'] ?? false) === true) {
                $uniques[(string) $index['name']] = array_map('strtolower', (array) $index['columns']);
            }
        }

        foreach ([
            'uq_kb_doc_tenant_version' => ['tenant_id', 'project_key', 'source_path', 'version_hash'],
            'uq_kb_doc_tenant_doc_id' => ['tenant_id', 'project_key', 'doc_id'],
            'uq_kb_doc_tenant_slug' => ['tenant_id', 'project_key', 'slug'],
        ] as $name => $columns) {
            $this->assertArrayHasKey($name, $uniques, "the tenant-scoped unique [{$name}] is missing");
            $this->assertSame($columns, $uniques[$name], "[{$name}] must be keyed on tenant_id first");
        }

        foreach (['uq_kb_doc_version', 'uq_kb_doc_doc_id', 'uq_kb_doc_slug'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $uniques, "the project-keyed unique [{$legacy}] still shadows the tenant-scoped one");
        }
    }

    /**
     * The other half of the same contract: the write the lookup leads to must
     * actually be accepted. Two tenants writing the same
     * `(project_key, source_path, version_hash)` tuple is a legal state, not
     * a `QueryException` the caller has to catch.
     */
    public function test_two_tenants_can_hold_the_same_version_tuple_at_the_database_level(): void
    {
        $tenantContext = app(TenantContext::class);
        $row = static fn (string $tenant): array => [
            'tenant_id' => $tenant,
            'project_key' => 'demo',
            'source_type' => 'markdown',
            'title' => 'Intro',
            'source_path' => 'docs/intro.md',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'status' => 'indexed',
            // The canonical slots too: all three uniques are the same claim.
            'is_canonical' => true,
            'doc_id' => 'dec-shared',
            'slug' => 'dec-shared',
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
        ];

        $tenantContext->set('tenant-a');
        KnowledgeDocument::create($row('tenant-a'));
        $tenantContext->set('tenant-b');
        KnowledgeDocument::create($row('tenant-b'));

        $this->assertSame(
            2,
            KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'docs/intro.md')->count(),
        );
    }
}
