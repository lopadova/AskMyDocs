<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Pipeline;

use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Contracts\EnricherInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\EnrichmentLevel;
use App\Services\Kb\Pipeline\SourceDocument;
use PHPUnit\Framework\TestCase;

final class ContractsTest extends TestCase
{
    public function test_source_document_dto_is_immutable_value_object(): void
    {
        $doc = new SourceDocument(
            sourcePath: 'docs/test.md',
            mimeType: 'text/markdown',
            bytes: '# Hello',
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: ['language' => 'en'],
        );

        self::assertSame('docs/test.md', $doc->sourcePath);
        self::assertSame('text/markdown', $doc->mimeType);
        self::assertSame('# Hello', $doc->bytes);
        self::assertNull($doc->externalUrl);
        self::assertNull($doc->externalId);
        self::assertSame('local', $doc->connectorType);
        self::assertSame(['language' => 'en'], $doc->metadata);
    }

    public function test_converted_document_holds_markdown_and_extraction_meta(): void
    {
        $cd = new ConvertedDocument(
            markdown: '# Title',
            mediaItems: [],
            extractionMeta: ['converter' => 'markdown-passthrough', 'duration_ms' => 0],
            sourceMimeType: 'text/markdown',
        );

        self::assertSame('# Title', $cd->markdown);
        self::assertSame([], $cd->mediaItems);
        self::assertArrayHasKey('converter', $cd->extractionMeta);
        self::assertSame('text/markdown', $cd->sourceMimeType);
    }

    public function test_chunk_draft_carries_text_heading_path_and_metadata(): void
    {
        $c = new ChunkDraft(
            text: 'body',
            order: 0,
            headingPath: 'H1 > H2',
            metadata: ['filename' => 'a.md'],
        );

        self::assertSame('body', $c->text);
        self::assertSame(0, $c->order);
        self::assertSame('H1 > H2', $c->headingPath);
        self::assertSame(['filename' => 'a.md'], $c->metadata);
    }

    public function test_enrichment_level_enum_has_three_values(): void
    {
        self::assertSame('none', EnrichmentLevel::NONE->value);
        self::assertSame('basic', EnrichmentLevel::BASIC->value);
        self::assertSame('full', EnrichmentLevel::FULL->value);
    }

    public function test_converter_interface_has_required_methods(): void
    {
        $rc = new \ReflectionClass(ConverterInterface::class);

        self::assertTrue($rc->isInterface());
        self::assertTrue($rc->hasMethod('name'));
        self::assertTrue($rc->hasMethod('supports'));
        self::assertTrue($rc->hasMethod('convert'));
    }

    public function test_chunker_interface_has_required_methods(): void
    {
        $rc = new \ReflectionClass(ChunkerInterface::class);

        self::assertTrue($rc->isInterface());
        self::assertTrue($rc->hasMethod('name'));
        self::assertTrue($rc->hasMethod('supports'));
        self::assertTrue($rc->hasMethod('chunk'));
    }

    public function test_enricher_interface_has_required_methods(): void
    {
        $rc = new \ReflectionClass(EnricherInterface::class);

        self::assertTrue($rc->isInterface());
        self::assertTrue($rc->hasMethod('name'));
        self::assertTrue($rc->hasMethod('appliesAt'));
        self::assertTrue($rc->hasMethod('enrich'));
    }
}
