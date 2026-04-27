<?php

declare(strict_types=1);

namespace App\Services\Kb;

use App\Services\Kb\Canonical\WikilinkExtractor;
use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;
use Illuminate\Support\Collection;

/**
 * Section-aware markdown chunker.
 *
 * Two modes, chosen automatically per document:
 *
 *  - **section_aware** — activated when the document contains at least one
 *    ATX heading. Emits one chunk per heading section with `heading_path`
 *    as a " > "-joined breadcrumb of H1-H3 ancestors. Oversized sections
 *    are split on paragraph boundaries under the hard-cap token budget.
 *
 *  - **paragraph_split** — fallback for markdown without headings. Splits
 *    on `\n{2,}` (blank-line separated blocks). Preserves pre-v2 behaviour
 *    for back-compat with existing consumers.
 *
 * Both modes strip YAML frontmatter first (canonical parser consumes it)
 * and attach `metadata.wikilinks` extracted by {@see WikilinkExtractor}.
 *
 * Token count is approximate (`strlen / 4`). Exact tokenization is the
 * embedding provider's concern; here we only need a hard-cap gate.
 *
 * v3.0 contract surface (T1.2):
 *  - {@see chunk()} now accepts a {@see ConvertedDocument} and returns
 *    `list<ChunkDraft>` per {@see ChunkerInterface}.
 *  - The pre-v3 `chunk(string $filename, string $markdown): Collection`
 *    is preserved verbatim under {@see chunkLegacy()} so DocumentIngestor
 *    and existing tests keep working until T1.4 cuts callers over.
 */
class MarkdownChunker implements ChunkerInterface
{
    private const FRONTMATTER_RE = '/\A---\r?\n.*?\r?\n---\r?\n?/s';
    private const HEADING_RE = '/^(#{1,6})\s+(.*?)\s*$/';
    private const FENCE_TOGGLE_RE = '/^\s{0,3}(`{3,}|~{3,})/';
    private const PARAGRAPH_SEP = '/\n{2,}/';
    private const CHARS_PER_TOKEN = 4;
    /**
     * Source-type tokens this chunker handles. Every converter that produces
     * markdown reuses this chunker (no DRY violation):
     *  - `markdown` / `md` — MarkdownPassthroughConverter (T1.3)
     *  - `text`            — TextPassthroughConverter wraps body in `# basename`
     *  - `pdf`             — PdfConverter (T1.5) emits `# basename` + `## Page N`
     *                        until T1.7 introduces a dedicated PdfPageChunker
     *                        that slices by page metadata directly.
     *  - `docx`            — DocxConverter (T1.6) outputs markdown headings.
     *
     * T1.7 will REMOVE `pdf` from this list and register PdfPageChunker for
     * the same source-type — at that point the registry's first-match-wins
     * rule will make PdfPageChunker the resolver.
     */
    private const SUPPORTED_SOURCE_TYPES = ['markdown', 'md', 'text', 'pdf', 'docx'];

    private WikilinkExtractor $wikilinks;

    public function __construct(?WikilinkExtractor $wikilinks = null)
    {
        $this->wikilinks = $wikilinks ?? new WikilinkExtractor();
    }

    public function name(): string
    {
        return 'markdown-section-aware';
    }

    public function supports(string $sourceType): bool
    {
        return in_array($sourceType, self::SUPPORTED_SOURCE_TYPES, true);
    }

    /**
     * v3 pipeline entry point — accepts a ConvertedDocument and returns ChunkDraft[].
     *
     * Filename for `metadata.filename` is read from `extractionMeta['filename']`
     * (set by the converter or DocumentIngestor) — only when it is a non-empty
     * string. Anything else (missing key, null, empty string, array, int, ...)
     * falls back to 'unknown.md' so downstream embedding/persistence never sees
     * a missing key OR a meaningless value like the literal `'Array'` produced
     * by string-casting an array.
     *
     * @return list<ChunkDraft>
     */
    public function chunk(ConvertedDocument $doc): array
    {
        $filename = $this->resolveFilename($doc->extractionMeta['filename'] ?? null);
        $legacy = $this->chunkLegacy($filename, $doc->markdown);

        return $legacy
            ->map(fn (array $chunk, int $i): ChunkDraft => new ChunkDraft(
                text: $chunk['text'],
                order: $i,
                headingPath: $chunk['heading_path'] ?? '',
                metadata: $chunk['metadata'],
            ))
            ->values()
            ->all();
    }

    private function resolveFilename(mixed $raw): string
    {
        if (! is_string($raw)) {
            return 'unknown.md';
        }
        $trimmed = trim($raw);
        return $trimmed === '' ? 'unknown.md' : $trimmed;
    }

    /**
     * Legacy entry point — preserved verbatim for DocumentIngestor and existing
     * unit tests until T1.4 swaps callers to {@see chunk()}.
     *
     * @return Collection<int, array{text:string, heading_path:?string, metadata:array<string,mixed>}>
     */
    public function chunkLegacy(string $filename, string $markdown): Collection
    {
        $body = $this->stripFrontmatter($markdown);
        if (trim($body) === '') {
            return collect();
        }

        $sectionAware = $this->hasRealHeading($body);
        $sections = $sectionAware ? $this->splitBySections($body) : $this->splitByParagraphs($body);
        $strategy = $sectionAware ? 'section_aware' : 'paragraph_split';
        $hardCap = $this->hardCapTokens();

        return $this->buildChunks($sections, $strategy, $hardCap, $filename);
    }

    // -----------------------------------------------------------------
    // fence-aware heading detection
    // -----------------------------------------------------------------

    /**
     * Does the document contain at least one ATX heading OUTSIDE fenced
     * code blocks? Lines inside ```...``` or ~~~...~~~ fences that start
     * with `#` (shell prompts, `#include`, example markdown) are NOT
     * treated as headings.
     */
    private function hasRealHeading(string $body): bool
    {
        $lines = preg_split('/\r?\n/', $body) ?: [];
        $inFence = false;
        foreach ($lines as $line) {
            if ($this->isFenceToggle($line)) {
                $inFence = ! $inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }
            if (preg_match(self::HEADING_RE, $line) === 1) {
                return true;
            }
        }
        return false;
    }

    private function isFenceToggle(string $line): bool
    {
        return preg_match(self::FENCE_TOGGLE_RE, $line) === 1;
    }

    /**
     * @param  list<array{text:string, heading_path:string|null}>  $sections
     * @return Collection<int, array{text:string, heading_path:?string, metadata:array<string,mixed>}>
     */
    private function buildChunks(array $sections, string $strategy, int $hardCap, string $filename): Collection
    {
        $chunks = [];
        foreach ($sections as $section) {
            $pieces = $this->enforceHardCap($section['text'], $hardCap);
            foreach ($pieces as $piece) {
                $chunk = $this->makeChunk($piece, $section['heading_path'], $strategy, $filename, count($chunks));
                if ($chunk === null) {
                    continue;
                }
                $chunks[] = $chunk;
            }
        }
        return collect($chunks)->values();
    }

    /**
     * @return array{text:string, heading_path:?string, metadata:array<string,mixed>}|null
     */
    private function makeChunk(string $piece, ?string $headingPath, string $strategy, string $filename, int $order): ?array
    {
        $trimmed = trim($piece);
        if ($trimmed === '') {
            return null;
        }
        return [
            'text' => $trimmed,
            'heading_path' => $headingPath,
            'metadata' => [
                'filename' => $filename,
                'strategy' => $strategy,
                'order' => $order,
                'wikilinks' => $this->wikilinks->extract($trimmed),
            ],
        ];
    }

    // -----------------------------------------------------------------
    // frontmatter stripping
    // -----------------------------------------------------------------

    private function stripFrontmatter(string $markdown): string
    {
        $stripped = preg_replace(self::FRONTMATTER_RE, '', $markdown, 1);
        return $stripped ?? $markdown;
    }

    // -----------------------------------------------------------------
    // section_aware mode
    // -----------------------------------------------------------------

    /**
     * Walk lines, accumulate section bodies between ATX headings (respecting
     * fenced code blocks so `#` inside a ``` fence is not treated as a
     * heading), maintain a heading stack to compose `heading_path`
     * breadcrumbs.
     *
     * @return list<array{text:string, heading_path:string}>
     */
    private function splitBySections(string $body): array
    {
        $lines = preg_split('/\r?\n/', $body) ?: [];
        $state = [
            'stack' => array_fill(0, 6, null),
            'path' => '',
            'buffer' => '',
            'sections' => [],
            'inFence' => false,
        ];

        foreach ($lines as $line) {
            $state = $this->advanceSectionState($state, $line);
        }
        return $this->flushSection($state)['sections'];
    }

    /**
     * Advance the one-line finite-state-machine for splitBySections.
     *
     * Three disjoint paths (bad/edge cases first, happy path last):
     *  - fence toggle → flip inFence, keep line in the buffer
     *  - inside a fence → keep line in the buffer, no heading detection
     *  - heading outside fence → flush previous section + update stack
     *  - prose → append to buffer
     *
     * @param  array{stack: array<int, string|null>, path: string, buffer: string, sections: list<array>, inFence: bool}  $state
     * @return array{stack: array<int, string|null>, path: string, buffer: string, sections: list<array>, inFence: bool}
     */
    private function advanceSectionState(array $state, string $line): array
    {
        if ($this->isFenceToggle($line)) {
            $state['inFence'] = ! $state['inFence'];
            $state['buffer'] .= $line . "\n";
            return $state;
        }
        if ($state['inFence']) {
            $state['buffer'] .= $line . "\n";
            return $state;
        }
        if (preg_match(self::HEADING_RE, $line, $m) !== 1) {
            $state['buffer'] .= $line . "\n";
            return $state;
        }

        $state = $this->flushSection($state);
        $state['stack'] = $this->updateHeadingStack($state['stack'], (int) strlen($m[1]), trim($m[2]));
        $state['path'] = $this->renderHeadingPath($state['stack']);
        return $state;
    }

    /**
     * @param  array{stack: array<int, string|null>, path: string, buffer: string, sections: list<array>}  $state
     * @return array{stack: array<int, string|null>, path: string, buffer: string, sections: list<array>}
     */
    private function flushSection(array $state): array
    {
        if (trim($state['buffer']) === '') {
            $state['buffer'] = '';
            return $state;
        }
        $state['sections'][] = ['text' => trim($state['buffer']), 'heading_path' => $state['path']];
        $state['buffer'] = '';
        return $state;
    }

    /**
     * @param  array<int, string|null>  $stack
     * @return array<int, string|null>
     */
    private function updateHeadingStack(array $stack, int $level, string $heading): array
    {
        $stack[$level - 1] = $heading;
        return array_map(
            static fn ($v, $i) => $i < $level ? $v : null,
            $stack,
            array_keys($stack),
        );
    }

    /**
     * @param  array<int, string|null>  $stack
     */
    private function renderHeadingPath(array $stack): string
    {
        $nonNull = array_filter($stack, static fn ($v) => $v !== null && $v !== '');
        return implode(' > ', $nonNull);
    }

    // -----------------------------------------------------------------
    // paragraph_split mode
    // -----------------------------------------------------------------

    /**
     * @return list<array{text:string, heading_path:null}>
     */
    private function splitByParagraphs(string $body): array
    {
        $parts = preg_split(self::PARAGRAPH_SEP, trim($body)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $out[] = ['text' => $part, 'heading_path' => null];
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // hard-cap enforcement
    // -----------------------------------------------------------------

    /**
     * If `$text` fits the hard-cap, return it as a single element. Otherwise
     * accumulate paragraphs into pieces, each under the cap. A single
     * paragraph larger than the cap is returned as-is (the embedding
     * provider can truncate, but we never silently drop content).
     *
     * @return list<string>
     */
    private function enforceHardCap(string $text, int $hardCapTokens): array
    {
        if ($this->estimateTokens($text) <= $hardCapTokens) {
            return [$text];
        }

        return $this->accumulateParagraphs($text, $hardCapTokens);
    }

    /**
     * @return list<string>
     */
    private function accumulateParagraphs(string $text, int $hardCapTokens): array
    {
        $paragraphs = preg_split(self::PARAGRAPH_SEP, $text) ?: [];
        $out = [];
        $buffer = '';
        foreach ($paragraphs as $para) {
            $trimmed = trim($para);
            if ($trimmed === '') {
                continue;
            }
            $buffer = $this->appendOrFlush($buffer, $trimmed, $hardCapTokens, $out);
        }
        if ($buffer !== '') {
            $out[] = $buffer;
        }
        if ($out === []) {
            return [$text];
        }
        return $out;
    }

    /**
     * Either append the paragraph to the running buffer or flush the buffer
     * to `$out` and start a fresh buffer with the paragraph. Returns the
     * new buffer contents.
     *
     * @param  list<string>  $out  accumulator, mutated in place
     */
    private function appendOrFlush(string $buffer, string $paragraph, int $cap, array &$out): string
    {
        $candidate = $buffer === '' ? $paragraph : $buffer . "\n\n" . $paragraph;
        if ($buffer === '') {
            return $candidate;
        }
        if ($this->estimateTokens($candidate) <= $cap) {
            return $candidate;
        }
        $out[] = $buffer;
        return $paragraph;
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    // -----------------------------------------------------------------
    // config access (decoupled from container for pure-unit testability)
    // -----------------------------------------------------------------

    private function hardCapTokens(): int
    {
        if (! function_exists('config') || ! function_exists('app')) {
            return 1024;
        }
        try {
            if (! app()->bound('config')) {
                return 1024;
            }
        } catch (\Throwable) {
            return 1024;
        }
        return (int) config('kb.chunking.hard_cap_tokens', 1024);
    }
}
