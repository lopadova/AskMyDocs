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
        $chunker = Mockery::mock(MarkdownChunker::class);
        $cache = Mockery::mock(EmbeddingCacheService::class);

        $chunker->shouldReceive('chunkLegacy')->twice()->andReturn(
            collect([[
                'text' => 'first chunk',
                'heading_path' => null,
                'metadata' => [],
            ]])
        );

        $cache->shouldReceive('generate')->twice()->andReturn(
            new EmbeddingsResponse(
                embeddings: [[0.1, 0.2, 0.3]],
                provider: 'openai',
                model: 'text-embedding-3-small',
            )
        );

        $ingestor = new DocumentIngestor($chunker, $cache);

        $first = $ingestor->ingestMarkdown('proj-a', '/docs/readme.md', 'Readme', '# v1');
        $second = $ingestor->ingestMarkdown('proj-a', '/docs/readme.md', 'Readme', '# v2');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, KnowledgeDocument::where('project_key', 'proj-a')->count());
        $this->assertSame('archived', KnowledgeDocument::find($first->id)?->status);
        $this->assertSame('active', KnowledgeDocument::find($second->id)?->status);
    }
}
