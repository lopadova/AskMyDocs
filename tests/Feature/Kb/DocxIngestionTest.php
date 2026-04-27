<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Pipeline\SourceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Fixtures\Docx\DocxFixtureBuilder;
use Tests\TestCase;

final class DocxIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_docx_ingestion_creates_document_with_docx_source_type(): void
    {
        $bytes = DocxFixtureBuilder::buildHeadingsSample();

        $kdoc = app(DocumentIngestor::class)->ingest(
            'test-project',
            new SourceDocument(
                sourcePath: 'reports/spec.docx',
                mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            ),
            title: 'Spec',
        );

        $this->assertSame('Spec', $kdoc->title);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $kdoc->mime_type,
        );
        $this->assertSame('docx', $kdoc->source_type);
        $this->assertSame('reports/spec.docx', $kdoc->source_path);
    }

    public function test_docx_ingestion_emits_chunks_via_section_aware_markdown_chunker(): void
    {
        $bytes = DocxFixtureBuilder::buildHeadingsSample();

        $kdoc = app(DocumentIngestor::class)->ingest(
            'test-project',
            new SourceDocument(
                sourcePath: 'reports/spec.docx',
                mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            ),
            title: 'Spec',
        );

        $chunks = $kdoc->chunks()->orderBy('chunk_order')->get();

        // The fixture has H1 "Introduction" + body + H2 "Background" + body.
        // section_aware should produce ≥2 chunks (one per heading section).
        $this->assertGreaterThanOrEqual(2, $chunks->count());
        // The basename owns the doc-level H1, so chunk heading_paths nest
        // under it (e.g. "spec.docx > Introduction").
        $headingPaths = $chunks->pluck('heading_path')->all();
        $this->assertTrue(
            collect($headingPaths)->contains(fn ($hp) => str_contains((string) $hp, 'Introduction')),
            'expected at least one chunk with heading_path containing "Introduction"; got ' . json_encode($headingPaths),
        );
    }

    public function test_docx_ingestion_propagates_converter_meta_to_document(): void
    {
        $bytes = DocxFixtureBuilder::buildHeadingsSample();

        $kdoc = app(DocumentIngestor::class)->ingest(
            'test-project',
            new SourceDocument(
                sourcePath: 'docs/meta.docx',
                mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            ),
            title: 'Meta',
        );
        $kdoc->refresh();

        $this->assertSame('docx-converter', $kdoc->metadata['converter']['converter']);
        $this->assertSame('meta.docx', $kdoc->metadata['converter']['filename']);
        $this->assertGreaterThanOrEqual(1, $kdoc->metadata['converter']['section_count']);
        $this->assertGreaterThanOrEqual(1, $kdoc->metadata['converter']['block_count']);
    }
}
