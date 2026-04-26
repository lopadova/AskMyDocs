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
    public function test_source_document_exposes_constructor_args_as_readonly_properties(): void
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

    public function test_source_document_throws_on_attempted_mutation_proving_immutability(): void
    {
        $doc = new SourceDocument(
            sourcePath: 'a.md',
            mimeType: 'text/markdown',
            bytes: 'x',
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/Cannot modify readonly property/');
        // @phpstan-ignore-next-line — intentional readonly violation to prove the invariant.
        $doc->sourcePath = 'mutated.md';
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

    public function test_converter_interface_has_required_method_signatures(): void
    {
        $rc = new \ReflectionClass(ConverterInterface::class);

        self::assertTrue($rc->isInterface());

        $name = $rc->getMethod('name');
        self::assertSame(0, $name->getNumberOfParameters());
        self::assertSame('string', (string) $name->getReturnType());

        $supports = $rc->getMethod('supports');
        self::assertSame(1, $supports->getNumberOfParameters());
        self::assertSame('mimeType', $supports->getParameters()[0]->getName());
        self::assertSame('string', (string) $supports->getParameters()[0]->getType());
        self::assertSame('bool', (string) $supports->getReturnType());

        $convert = $rc->getMethod('convert');
        self::assertSame(1, $convert->getNumberOfParameters());
        self::assertSame('doc', $convert->getParameters()[0]->getName());
        self::assertSame(SourceDocument::class, (string) $convert->getParameters()[0]->getType());
        self::assertSame(ConvertedDocument::class, (string) $convert->getReturnType());
    }

    public function test_chunker_interface_has_required_method_signatures(): void
    {
        $rc = new \ReflectionClass(ChunkerInterface::class);

        self::assertTrue($rc->isInterface());

        $name = $rc->getMethod('name');
        self::assertSame(0, $name->getNumberOfParameters());
        self::assertSame('string', (string) $name->getReturnType());

        $supports = $rc->getMethod('supports');
        self::assertSame(1, $supports->getNumberOfParameters());
        self::assertSame('sourceType', $supports->getParameters()[0]->getName());
        self::assertSame('string', (string) $supports->getParameters()[0]->getType());
        self::assertSame('bool', (string) $supports->getReturnType());

        $chunk = $rc->getMethod('chunk');
        self::assertSame(1, $chunk->getNumberOfParameters());
        self::assertSame('doc', $chunk->getParameters()[0]->getName());
        self::assertSame(ConvertedDocument::class, (string) $chunk->getParameters()[0]->getType());
        self::assertSame('array', (string) $chunk->getReturnType());
    }

    public function test_enricher_interface_has_required_method_signatures(): void
    {
        $rc = new \ReflectionClass(EnricherInterface::class);

        self::assertTrue($rc->isInterface());

        $name = $rc->getMethod('name');
        self::assertSame(0, $name->getNumberOfParameters());
        self::assertSame('string', (string) $name->getReturnType());

        $appliesAt = $rc->getMethod('appliesAt');
        self::assertSame(1, $appliesAt->getNumberOfParameters());
        self::assertSame('level', $appliesAt->getParameters()[0]->getName());
        self::assertSame(EnrichmentLevel::class, (string) $appliesAt->getParameters()[0]->getType());
        self::assertSame('bool', (string) $appliesAt->getReturnType());

        $enrich = $rc->getMethod('enrich');
        self::assertSame(2, $enrich->getNumberOfParameters());
        self::assertSame('chunk', $enrich->getParameters()[0]->getName());
        self::assertSame(ChunkDraft::class, (string) $enrich->getParameters()[0]->getType());
        self::assertSame('context', $enrich->getParameters()[1]->getName());
        self::assertSame('array', (string) $enrich->getParameters()[1]->getType());
        self::assertSame(ChunkDraft::class, (string) $enrich->getReturnType());
    }
}
