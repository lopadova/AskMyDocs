<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Pipeline\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Fixtures\Pdf\PdfFixtureBuilder;
use Tests\TestCase;

final class PdfIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub the embedding cache so we don't hit a real provider. The shape
        // (one vector per chunk, in array order) is the only contract the
        // ingestor relies on; values irrelevant for these assertions.
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(fn () => array_fill(0, 768, 0.0), $texts),
                provider: 'fake',
                model: 'fake-768',
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_pdf_ingestion_creates_document_with_pdf_source_type(): void
    {
        $bytes = PdfFixtureBuilder::buildThreePageSample();

        $kdoc = app(DocumentIngestor::class)->ingest(
            'test-project',
            new SourceDocument(
                sourcePath: 'reports/q1.pdf',
                mimeType: 'application/pdf',
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            ),
            title: 'Q1 Report',
        );

        $this->assertSame('Q1 Report', $kdoc->title);
        $this->assertSame('application/pdf', $kdoc->mime_type);
        $this->assertSame('pdf', $kdoc->source_type);
        $this->assertSame('reports/q1.pdf', $kdoc->source_path);
    }

    public function test_pdf_ingestion_emits_one_chunk_per_page_via_pdf_page_chunker(): void
    {
        $bytes = PdfFixtureBuilder::buildThreePageSample();

        $kdoc = app(DocumentIngestor::class)->ingest(
            'test-project',
            new SourceDocument(
                sourcePath: 'reports/q1.pdf',
                mimeType: 'application/pdf',
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            ),
            title: 'Q1',
        );

        $chunks = $kdoc->chunks()->orderBy('chunk_order')->get();

        $this->assertGreaterThanOrEqual(3, $chunks->count(), 'expect ≥1 chunk per page');
        // PdfPageChunker (registered first in chunkers list, takes 'pdf'
        // source-type via registry first-match-wins) sets heading_path to
        // "Page N" — basename lives in metadata.filename for the citation
        // renderer to compose "page N of foo.pdf".
        $this->assertSame('Page 1', (string) $chunks->first()->heading_path);
    }

    public function test_pdf_ingestion_propagates_converter_meta_to_document(): void
    {
        $bytes = PdfFixtureBuilder::buildSinglePage('Single page body for meta check.');

        $kdoc = app(DocumentIngestor::class)->ingest(
            'test-project',
            new SourceDocument(
                sourcePath: 'docs/meta.pdf',
                mimeType: 'application/pdf',
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            ),
            title: 'Meta',
        );
        $kdoc->refresh();

        $this->assertSame('pdf-converter', $kdoc->metadata['converter']['converter']);
        $this->assertSame('smalot', $kdoc->metadata['converter']['extraction_strategy']);
        $this->assertSame(1, $kdoc->metadata['converter']['page_count']);
        $this->assertSame('meta.pdf', $kdoc->metadata['converter']['filename']);
    }
}
