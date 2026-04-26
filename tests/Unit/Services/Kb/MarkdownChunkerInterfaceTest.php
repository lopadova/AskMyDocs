<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb;

use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\MarkdownChunker;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T1.2 — Parity test for MarkdownChunker behind ChunkerInterface.
 *
 * Asserts the v3 pipeline contract (name/supports/chunk(ConvertedDocument)) is
 * satisfied AND the legacy (filename, markdown): Collection signature still works
 * — the latter is consumed by DocumentIngestor and 22 callsites in the existing
 * MarkdownChunkerTest until T1.4 cuts the persistence layer over to the new
 * signature.
 */
final class MarkdownChunkerInterfaceTest extends TestCase
{
    public function test_implements_chunker_interface(): void
    {
        $this->assertInstanceOf(ChunkerInterface::class, app(MarkdownChunker::class));
    }

    public function test_name_is_stable_kebab_identifier(): void
    {
        $this->assertSame('markdown-section-aware', app(MarkdownChunker::class)->name());
    }

    public function test_supports_markdown_and_md(): void
    {
        $chunker = app(MarkdownChunker::class);
        $this->assertTrue($chunker->supports('markdown'));
        $this->assertTrue($chunker->supports('md'));
        $this->assertFalse($chunker->supports('pdf'));
        $this->assertFalse($chunker->supports('docx'));
    }

    public function test_chunk_method_accepts_converted_document(): void
    {
        $chunker = app(MarkdownChunker::class);
        $cd = new ConvertedDocument(
            markdown: "# H1\n\nbody.\n\n## H2\n\nbody2.",
            mediaItems: [],
            extractionMeta: ['converter' => 'markdown-passthrough', 'filename' => 'sample.md'],
            sourceMimeType: 'text/markdown',
        );

        $chunks = $chunker->chunk($cd);

        $this->assertCount(2, $chunks);
        $this->assertContainsOnlyInstancesOf(ChunkDraft::class, $chunks);
        $this->assertSame('H1', $chunks[0]->headingPath);
        $this->assertSame('H1 > H2', $chunks[1]->headingPath);
        $this->assertSame(0, $chunks[0]->order);
        $this->assertSame(1, $chunks[1]->order);
        $this->assertSame('sample.md', $chunks[0]->metadata['filename']);
    }

    public function test_chunk_uses_unknown_md_when_filename_missing_from_meta(): void
    {
        $chunker = app(MarkdownChunker::class);
        $cd = new ConvertedDocument(
            markdown: "Just a paragraph.",
            mediaItems: [],
            extractionMeta: [],
            sourceMimeType: 'text/markdown',
        );

        $chunks = $chunker->chunk($cd);

        $this->assertCount(1, $chunks);
        $this->assertSame('unknown.md', $chunks[0]->metadata['filename']);
    }

    /**
     * Filename derivation must be defensive: a non-string OR an empty/whitespace-only
     * string in `extractionMeta['filename']` must fall back to 'unknown.md'.
     * Without the guard, string-casting an array yields the literal `'Array'`,
     * an empty string is preserved verbatim, and downstream metadata becomes
     * meaningless.
     */
    #[DataProvider('invalidFilenameProvider')]
    public function test_chunk_falls_back_to_unknown_md_for_invalid_filename(mixed $invalid): void
    {
        $chunker = app(MarkdownChunker::class);
        $cd = new ConvertedDocument(
            markdown: "Just a paragraph.",
            mediaItems: [],
            extractionMeta: ['filename' => $invalid],
            sourceMimeType: 'text/markdown',
        );

        $chunks = $chunker->chunk($cd);

        $this->assertCount(1, $chunks);
        $this->assertSame('unknown.md', $chunks[0]->metadata['filename']);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidFilenameProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace only' => ["   \t\n"];
        yield 'array' => [['x.md']];
        yield 'integer' => [42];
        yield 'boolean false' => [false];
    }

    public function test_chunk_returns_empty_array_for_blank_markdown(): void
    {
        $chunker = app(MarkdownChunker::class);
        $cd = new ConvertedDocument(
            markdown: "",
            mediaItems: [],
            extractionMeta: ['filename' => 'blank.md'],
            sourceMimeType: 'text/markdown',
        );

        $this->assertSame([], $chunker->chunk($cd));
    }

    /**
     * Plan §571 explicitly preserves the existing public chunk() under chunkLegacy()
     * so DocumentIngestor and the 22 callsites in tests/Unit/Kb/MarkdownChunkerTest.php
     * keep working until T1.4 swaps callers to the new ConvertedDocument signature.
     *
     * Real existing signature is `(string $filename, string $markdown): Collection`
     * — preserved verbatim under the renamed method.
     */
    public function test_legacy_chunk_method_still_works_for_backwards_compat(): void
    {
        $chunker = app(MarkdownChunker::class);
        $chunks = $chunker->chunkLegacy('a.md', "# H1\n\nbody");

        $this->assertNotEmpty($chunks);
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $chunks);
        $this->assertSame('H1', $chunks[0]['heading_path']);
        $this->assertSame('body', $chunks[0]['text']);
        $this->assertSame('a.md', $chunks[0]['metadata']['filename']);
    }
}
