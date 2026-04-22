<?php

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Jobs\CanonicalIndexerJob;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\MarkdownChunker;
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
        $cache = $this->fakeEmbeddingCache(1);

        $ingestor = new DocumentIngestor(new MarkdownChunker(), $cache);
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
        $cache = $this->fakeEmbeddingCache(1);

        $ingestor = new DocumentIngestor(new MarkdownChunker(), $cache);
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
        $cache = $this->fakeEmbeddingCache(1);

        $ingestor = new DocumentIngestor(new MarkdownChunker(), $cache);
        // Unclosed quote → YAML parse error → validation fails → fall through.
        $markdown = "---\nslug: \"broken\ntype: decision\nstatus: accepted\n---\n\n# Body";

        $doc = $ingestor->ingestMarkdown('acme', 'docs/broken.md', 'Broken', $markdown);

        $this->assertFalse($doc->is_canonical);
        Queue::assertNotPushed(CanonicalIndexerJob::class);
    }

    public function test_frontmatter_missing_required_slug_degrades_to_non_canonical(): void
    {
        Queue::fake();
        $cache = $this->fakeEmbeddingCache(1);

        $ingestor = new DocumentIngestor(new MarkdownChunker(), $cache);
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
        $cache = $this->fakeEmbeddingCache(1);

        $ingestor = new DocumentIngestor(new MarkdownChunker(), $cache);
        $markdown = "---\nslug: dec-x\ntype: decision\nstatus: accepted\n---\n\n# Body";

        $doc = $ingestor->ingestMarkdown('acme', 'decisions/dec-x.md', 'Title', $markdown);

        $this->assertFalse($doc->is_canonical);
        Queue::assertNotPushed(CanonicalIndexerJob::class);
    }

    public function test_reingesting_identical_canonical_doc_is_noop(): void
    {
        Queue::fake();
        $cache = $this->fakeEmbeddingCache(1);
        $ingestor = new DocumentIngestor(new MarkdownChunker(), $cache);
        $markdown = "---\nslug: dec-x\ntype: decision\nstatus: accepted\n---\n\n# Body";

        $first = $ingestor->ingestMarkdown('acme', 'decisions/dec-x.md', 'T', $markdown);
        $second = $ingestor->ingestMarkdown('acme', 'decisions/dec-x.md', 'T', $markdown);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, KnowledgeDocument::count());
    }

    private function fakeEmbeddingCache(int $chunkCount): EmbeddingCacheService
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
