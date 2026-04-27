<?php

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Jobs\CanonicalIndexerJob;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class DocumentIngestorCanonicalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_canonical_markdown_populates_canonical_columns_and_dispatches_indexer(): void
    {
        Queue::fake();
        $cache = $this->fakeEmbeddingCache();

        $this->app->instance(EmbeddingCacheService::class, $cache);
        $ingestor = app(DocumentIngestor::class);
        $markdown = <<<'MD'
---
id: DEC-2026-0001
slug: dec-cache-v2
type: decision
status: accepted
retrieval_priority: 90
tags: [cache, invalidation]
owners:
  - platform-team
related:
  - "[[module-cache]]"
  - "[[runbook-purge]]"
---

# Cache invalidation v2

Body about caching.
MD;

        $doc = $ingestor->ingestMarkdown('acme', 'decisions/dec-cache-v2.md', 'Cache invalidation v2', $markdown);

        $this->assertTrue($doc->is_canonical);
        $this->assertSame('DEC-2026-0001', $doc->doc_id);
        $this->assertSame('dec-cache-v2', $doc->slug);
        $this->assertSame('decision', $doc->canonical_type);
        $this->assertSame('accepted', $doc->canonical_status);
        $this->assertSame(90, $doc->retrieval_priority);

        $derived = $doc->frontmatter_json['_derived'];
        $this->assertSame(['module-cache', 'runbook-purge'], $derived['related_slugs']);
        $this->assertSame(['platform-team'], $derived['owners']);
        $this->assertSame(['cache', 'invalidation'], $derived['tags']);

        Queue::assertPushed(CanonicalIndexerJob::class, fn ($job) => $job->documentId === $doc->id);
    }

    public function test_non_canonical_markdown_skips_canonical_path(): void
    {
        Queue::fake();
        $cache = $this->fakeEmbeddingCache();

        $this->app->instance(EmbeddingCacheService::class, $cache);
        $ingestor = app(DocumentIngestor::class);
        $doc = $ingestor->ingestMarkdown('acme', 'docs/plain.md', 'Plain', "# Heading\n\nBody.");

        $this->assertFalse($doc->is_canonical);
        $this->assertNull($doc->doc_id);
        $this->assertNull($doc->slug);
        $this->assertNull($doc->canonical_type);
        $this->assertNull($doc->frontmatter_json);

        Queue::assertNotPushed(CanonicalIndexerJob::class);
    }

    public function test_malformed_frontmatter_degrades_to_non_canonical(): void
    {
        Queue::fake();
        $cache = $this->fakeEmbeddingCache();

        $this->app->instance(EmbeddingCacheService::class, $cache);
        $ingestor = app(DocumentIngestor::class);
        // Unclosed quote → YAML parse error → validation fails → fall through.
        $markdown = "---\nslug: \"broken\ntype: decision\nstatus: accepted\n---\n\n# Body";

        $doc = $ingestor->ingestMarkdown('acme', 'docs/broken.md', 'Broken', $markdown);

        $this->assertFalse($doc->is_canonical);
        Queue::assertNotPushed(CanonicalIndexerJob::class);
    }

    public function test_frontmatter_missing_required_slug_degrades_to_non_canonical(): void
    {
        Queue::fake();
        $cache = $this->fakeEmbeddingCache();

        $this->app->instance(EmbeddingCacheService::class, $cache);
        $ingestor = app(DocumentIngestor::class);
        // Structurally valid YAML but missing the slug — validation fails.
        $markdown = "---\ntype: decision\nstatus: accepted\n---\n\n# Body";

        $doc = $ingestor->ingestMarkdown('acme', 'docs/missing-slug.md', 'Missing Slug', $markdown);

        $this->assertFalse($doc->is_canonical);
        Queue::assertNotPushed(CanonicalIndexerJob::class);
    }

    public function test_disabled_canonical_config_ignores_frontmatter(): void
    {
        config()->set('kb.canonical.enabled', false);
        Queue::fake();
        $cache = $this->fakeEmbeddingCache();

        $this->app->instance(EmbeddingCacheService::class, $cache);
        $ingestor = app(DocumentIngestor::class);
        $markdown = "---\nslug: dec-x\ntype: decision\nstatus: accepted\n---\n\n# Body";

        $doc = $ingestor->ingestMarkdown('acme', 'decisions/dec-x.md', 'Title', $markdown);

        $this->assertFalse($doc->is_canonical);
        Queue::assertNotPushed(CanonicalIndexerJob::class);
    }

    public function test_reingesting_identical_canonical_doc_is_noop(): void
    {
        Queue::fake();
        $cache = $this->fakeEmbeddingCache();
        $this->app->instance(EmbeddingCacheService::class, $cache);
        $ingestor = app(DocumentIngestor::class);
        $markdown = "---\nslug: dec-x\ntype: decision\nstatus: accepted\n---\n\n# Body";

        $first = $ingestor->ingestMarkdown('acme', 'decisions/dec-x.md', 'T', $markdown);
        $second = $ingestor->ingestMarkdown('acme', 'decisions/dec-x.md', 'T', $markdown);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, KnowledgeDocument::count());
    }

    /**
     * Regression for Copilot comment on PR #10: re-ingesting a canonical
     * doc with CHANGED content must not violate the uq_kb_doc_slug /
     * uq_kb_doc_doc_id composite unique on the archived prior version.
     * Older rows have their canonical identifiers vacated before the new
     * version is inserted.
     */
    public function test_reingesting_canonical_doc_with_changed_content_succeeds(): void
    {
        Queue::fake();
        $cache = $this->fakeEmbeddingCache();
        $this->app->instance(EmbeddingCacheService::class, $cache);
        $ingestor = app(DocumentIngestor::class);

        $v1 = "---\nid: DEC-2026-0001\nslug: dec-cache\ntype: decision\nstatus: accepted\n---\n\n# V1 body";
        $v2 = "---\nid: DEC-2026-0001\nslug: dec-cache\ntype: decision\nstatus: accepted\n---\n\n# V2 body (changed)";

        $first = $ingestor->ingestMarkdown('acme', 'decisions/dec-cache.md', 'T', $v1);
        $second = $ingestor->ingestMarkdown('acme', 'decisions/dec-cache.md', 'T', $v2);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, KnowledgeDocument::count());

        // Old row is archived and no longer holds canonical identity.
        $oldFresh = KnowledgeDocument::find($first->id);
        $this->assertSame('archived', $oldFresh->status);
        $this->assertFalse((bool) $oldFresh->is_canonical);
        $this->assertNull($oldFresh->doc_id);
        $this->assertNull($oldFresh->slug);
        $this->assertNull($oldFresh->canonical_status);

        // New row owns the slug + doc_id.
        $this->assertSame('dec-cache', $second->slug);
        $this->assertSame('DEC-2026-0001', $second->doc_id);
        $this->assertTrue((bool) $second->is_canonical);
    }

    /**
     * Mock that returns one dummy 3-float embedding per input text — lets
     * each test drive the chunk count via the markdown fixture itself.
     */
    private function fakeEmbeddingCache(): EmbeddingCacheService
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(function (array $texts) {
            $embeddings = array_map(
                static fn () => array_fill(0, 3, 0.1),
                array_values($texts),
            );
            return new EmbeddingsResponse(
                embeddings: $embeddings,
                provider: 'openai',
                model: 'text-embedding-3-small',
            );
        });
        return $cache;
    }
}
