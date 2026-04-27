<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunkers;

use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;

/**
 * Page-aware chunker for PDF source documents (T1.7).
 *
 * Slices the converted document on its `## Page N` heading boundaries
 * (emitted by {@see \App\Services\Kb\Converters\PdfConverter} for every
 * non-empty page) so each PDF page becomes its own chunk with a
 * `heading_path = "Page N"` breadcrumb. This is the natural unit for
 * citations: "see page N of foo.pdf" maps 1:1 to a single chunk row.
 *
 * Pages that exceed the configured `kb.chunking.hard_cap_tokens` budget
 * are split intra-page on `\n\n` paragraph boundaries (same hard-cap
 * mechanism MarkdownChunker uses), keeping subsequent pieces under the
 * cap with the same `heading_path` so citations still resolve to the
 * page even when its body had to be split.
 *
 * Registry routing: registered FIRST in `config/kb-pipeline.php` so the
 * registry's first-match-wins resolution prefers it over MarkdownChunker
 * for the `pdf` source-type. MarkdownChunker no longer supports `pdf`.
 */
final class PdfPageChunker implements ChunkerInterface
{
    private const SUPPORTED_SOURCE_TYPES = ['pdf'];
    private const PAGE_HEADING_RE = '/^##\s+Page\s+(\d+)\s*$/m';
    private const PARAGRAPH_SEP = '/\n{2,}/';
    private const CHARS_PER_TOKEN = 4;
    private const DEFAULT_HARD_CAP = 1024;

    public function name(): string
    {
        return 'pdf-page-chunker';
    }

    public function supports(string $sourceType): bool
    {
        return in_array($sourceType, self::SUPPORTED_SOURCE_TYPES, true);
    }

    /**
     * @return list<ChunkDraft>
     */
    public function chunk(ConvertedDocument $doc): array
    {
        $filename = $this->resolveFilename($doc->extractionMeta['filename'] ?? null);
        $pages = $this->splitByPageHeadings($doc->markdown);
        if ($pages === []) {
            return [];
        }

        $hardCap = $this->hardCapTokens();
        $drafts = [];
        $order = 0;
        foreach ($pages as $page) {
            $headingPath = 'Page ' . $page['number'];
            $pieces = $this->enforceHardCap($page['text'], $hardCap);
            foreach ($pieces as $piece) {
                $drafts[] = new ChunkDraft(
                    text: $piece,
                    order: $order++,
                    headingPath: $headingPath,
                    metadata: [
                        'filename' => $filename,
                        'strategy' => 'pdf-page',
                        'page' => $page['number'],
                    ],
                );
            }
        }
        return $drafts;
    }

    /**
     * Walks the markdown line-by-line collecting body text under each
     * `## Page N` heading. The doc-level `# {basename}` H1 (emitted by
     * PdfConverter) is dropped on purpose — page chunks are named by their
     * page number alone; the basename lives in `metadata.filename` for
     * citation rendering.
     *
     * @return list<array{number: int, text: string}>
     */
    private function splitByPageHeadings(string $markdown): array
    {
        $pages = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $markdown) ?: [] as $line) {
            if (preg_match(self::PAGE_HEADING_RE, $line, $m) === 1) {
                if ($current !== null) {
                    $pages[] = $this->finalizePage($current);
                }
                $current = ['number' => (int) $m[1], 'lines' => []];
                continue;
            }
            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }
        if ($current !== null) {
            $pages[] = $this->finalizePage($current);
        }

        // Drop pages with empty body so the chunker never emits heading-only
        // chunks (mirrors PdfConverter's empty-page guard at the upstream).
        return array_values(array_filter(
            $pages,
            static fn (array $p): bool => $p['text'] !== '',
        ));
    }

    /**
     * @param  array{number: int, lines: list<string>}  $current
     * @return array{number: int, text: string}
     */
    private function finalizePage(array $current): array
    {
        return [
            'number' => $current['number'],
            'text' => trim(implode("\n", $current['lines'])),
        ];
    }

    /**
     * If `$text` fits the hard-cap, return it as a single piece. Otherwise
     * split on paragraph boundaries and accumulate pieces under the cap.
     * A single paragraph larger than the cap is returned as-is — the
     * embedding provider can truncate, but the chunker never silently
     * drops content (same contract as MarkdownChunker).
     *
     * @return list<string>
     */
    private function enforceHardCap(string $text, int $cap): array
    {
        if ($this->estimateTokens($text) <= $cap) {
            return [$text];
        }

        $paragraphs = preg_split(self::PARAGRAPH_SEP, $text) ?: [];
        $out = [];
        $buffer = '';
        foreach ($paragraphs as $para) {
            $trimmed = trim($para);
            if ($trimmed === '') {
                continue;
            }
            if ($buffer === '') {
                $buffer = $trimmed;
                continue;
            }
            $candidate = $buffer . "\n\n" . $trimmed;
            if ($this->estimateTokens($candidate) <= $cap) {
                $buffer = $candidate;
                continue;
            }
            $out[] = $buffer;
            $buffer = $trimmed;
        }
        if ($buffer !== '') {
            $out[] = $buffer;
        }
        return $out === [] ? [$text] : $out;
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    private function resolveFilename(mixed $raw): string
    {
        if (! is_string($raw)) {
            return 'unknown.pdf';
        }
        $trimmed = trim($raw);
        return $trimmed === '' ? 'unknown.pdf' : $trimmed;
    }

    /**
     * Reads the same `kb.chunking.hard_cap_tokens` config knob MarkdownChunker
     * uses — keeping a single hard-cap value across chunkers means operators
     * tune one knob to control embedding-cost / chunk-size trade-offs.
     */
    private function hardCapTokens(): int
    {
        if (! function_exists('config') || ! function_exists('app')) {
            return self::DEFAULT_HARD_CAP;
        }
        try {
            if (! app()->bound('config')) {
                return self::DEFAULT_HARD_CAP;
            }
        } catch (\Throwable) {
            return self::DEFAULT_HARD_CAP;
        }
        return (int) config('kb.chunking.hard_cap_tokens', self::DEFAULT_HARD_CAP);
    }
}
