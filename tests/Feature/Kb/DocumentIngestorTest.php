<?php

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\MarkdownChunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DocumentIngestorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_archives_previous_versions_for_same_source_path(): void
    {
        // Post-T1.4 refactor: ingestMarkdown is a facade calling ingest() which
        // resolves the real MarkdownChunker via PipelineRegistry. Mocking the
        // chunker is no longer reachable through the singleton registry path,
        // so we use the real chunker (cheap — just regex + buffers) and only
        // mock the embedding cache (which would otherwise hit a real provider).
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->twice()->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(fn () => [0.1, 0.2, 0.3], $texts),
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);

        $ingestor = app(DocumentIngestor::class);

        $first = $ingestor->ingestMarkdown('proj-a', '/docs/readme.md', 'Readme', '# v1');
        $second = $ingestor->ingestMarkdown('proj-a', '/docs/readme.md', 'Readme', '# v2');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, KnowledgeDocument::where('project_key', 'proj-a')->count());
        $this->assertSame('archived', KnowledgeDocument::find($first->id)?->status);
        $this->assertSame('active', KnowledgeDocument::find($second->id)?->status);
    }
}
