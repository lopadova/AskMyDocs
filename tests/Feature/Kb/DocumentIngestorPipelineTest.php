<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Pipeline\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class DocumentIngestorPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub the embedding cache so we don't hit a real provider. The shape
        // (1 vector per chunk) is the only contract DocumentIngestor relies on;
        // the values are irrelevant for these assertions.
        $this->app->instance(EmbeddingCacheService::class, $this->fakeCache());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_ingest_text_file_via_pipeline_creates_document_with_text_source_type(): void
    {
        $ingestor = app(DocumentIngestor::class);
        $doc = new SourceDocument(
            sourcePath: 'docs/notes.txt',
            mimeType: 'text/plain',
            bytes: "Note alpha. Note beta. Note gamma.",
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: ['language' => 'en'],
        );

        $kdoc = $ingestor->ingest('test-project', $doc, title: 'Notes');

        $this->assertSame('Notes', $kdoc->title);
        $this->assertSame('text/plain', $kdoc->mime_type);
        $this->assertSame('text', $kdoc->source_type);
        $this->assertSame('en', $kdoc->language);
        $this->assertSame('docs/notes.txt', $kdoc->source_path);
        $this->assertGreaterThan(0, $kdoc->chunks()->count());
    }

    public function test_ingest_markdown_via_pipeline_lands_with_markdown_source_type(): void
    {
        $ingestor = app(DocumentIngestor::class);
        $md = "# Title\n\nbody.\n\n## Sub\n\nbody2.";
        $doc = new SourceDocument(
            sourcePath: 'docs/intro.md',
            mimeType: 'text/markdown',
            bytes: $md,
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $kdoc = $ingestor->ingest('test-project', $doc, title: 'Intro');

        $this->assertSame('markdown', $kdoc->source_type);
        $this->assertSame('text/markdown', $kdoc->mime_type);
        $this->assertSame(2, $kdoc->chunks()->count());
    }

    public function test_ingestMarkdown_facade_yields_same_chunk_count_as_direct_pipeline(): void
    {
        $ingestor = app(DocumentIngestor::class);
        $md = "# Title\n\nbody.\n\n## Sub\n\nbody2.";

        $kdocFacade = $ingestor->ingestMarkdown('test-project', 'docs/a.md', 'A', $md);
        $kdocDirect = $ingestor->ingest(
            'test-project',
            new SourceDocument(
                sourcePath: 'docs/b.md',
                mimeType: 'text/markdown',
                bytes: $md,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            ),
            title: 'B',
        );

        $this->assertSame($kdocFacade->chunks()->count(), $kdocDirect->chunks()->count());
        $this->assertSame('markdown', $kdocFacade->source_type);
        $this->assertSame('markdown', $kdocDirect->source_type);
    }

    public function test_ingest_unsupported_mime_throws_runtime_exception(): void
    {
        $ingestor = app(DocumentIngestor::class);
        $doc = new SourceDocument(
            sourcePath: 'a.bin',
            mimeType: 'application/octet-stream',
            bytes: 'arbitrary',
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $this->expectException(\RuntimeException::class);
        $ingestor->ingest('test-project', $doc, title: 'Bin');
    }

    public function test_ingest_records_converter_meta_and_connector_attribution(): void
    {
        $ingestor = app(DocumentIngestor::class);
        $doc = new SourceDocument(
            sourcePath: 'docs/from-notion.md',
            mimeType: 'text/markdown',
            bytes: "# T\n\nbody",
            externalUrl: 'https://notion.so/page-xyz',
            externalId: 'page-xyz',
            connectorType: 'notion',
            metadata: ['language' => 'it'],
        );

        $kdoc = $ingestor->ingest('test-project', $doc, title: 'From Notion');
        $kdoc->refresh();

        $this->assertSame('notion', $kdoc->metadata['connector']);
        $this->assertSame('https://notion.so/page-xyz', $kdoc->metadata['external_url']);
        $this->assertSame('page-xyz', $kdoc->metadata['external_id']);
        $this->assertSame('markdown-passthrough', $kdoc->metadata['converter']['converter']);
        $this->assertSame('docs/from-notion.md', $kdoc->metadata['converter']['source_path']);
        $this->assertSame('from-notion.md', $kdoc->metadata['converter']['filename']);
    }

    public function test_normalizes_empty_string_language_and_access_scope_to_defaults(): void
    {
        // Connectors may emit `'language' => ''` or `'access_scope' => '   '`
        // on partial payloads; bare `??` would preserve those blank strings
        // instead of falling back to the declared defaults. Verify defensive
        // normalisation treats blank metadata values as missing and restores
        // the domain defaults `'it'` / `'internal'`.
        $ingestor = app(DocumentIngestor::class);
        $doc = new SourceDocument(
            sourcePath: 'docs/empty-meta.md',
            mimeType: 'text/markdown',
            bytes: "# T\n\nbody",
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [
                'language' => '',
                'access_scope' => '   ',
            ],
        );

        $kdoc = $ingestor->ingest('test-project', $doc, title: 'Empty meta');
        $kdoc->refresh();

        $this->assertSame('it', $kdoc->language);
        $this->assertSame('internal', $kdoc->access_scope);
    }

    public function test_normalizes_non_string_language_to_default(): void
    {
        $ingestor = app(DocumentIngestor::class);
        $doc = new SourceDocument(
            sourcePath: 'docs/non-string-meta.md',
            mimeType: 'text/markdown',
            bytes: "# T\n\nbody",
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [
                'language' => 42,
                'access_scope' => false,
            ],
        );

        $kdoc = $ingestor->ingest('test-project', $doc, title: 'Non-string meta');
        $kdoc->refresh();

        $this->assertSame('it', $kdoc->language);
        $this->assertSame('internal', $kdoc->access_scope);
    }

    public function test_idempotency_via_version_hash_holds_for_new_pipeline_path(): void
    {
        $ingestor = app(DocumentIngestor::class);
        $doc = new SourceDocument(
            sourcePath: 'docs/idem.md',
            mimeType: 'text/markdown',
            bytes: "# H\n\nbody",
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $first = $ingestor->ingest('test-project', $doc, title: 'I');
        $second = $ingestor->ingest('test-project', $doc, title: 'I');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, KnowledgeDocument::where('project_key', 'test-project')->count());
    }

    private function fakeCache(): EmbeddingCacheService
    {
        $mock = Mockery::mock(EmbeddingCacheService::class);
        $mock->shouldReceive('generate')
            ->andReturnUsing(fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(fn () => array_fill(0, 768, 0.0), $texts),
                provider: 'fake',
                model: 'fake-768',
            ));

        return $mock;
    }
}
