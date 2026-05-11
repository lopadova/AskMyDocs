<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunkers;

use App\Services\Kb\Chunking\Support\DerivedMetadataReader;
use App\Services\Kb\Chunking\Support\TokenCounter;
use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;

/**
 * Page-hierarchy-aware chunker for Confluence pages (v4.5/W5.5).
 *
 * Confluence pages convert to markdown with H1-H4 sections preserved.
 * This chunker splits on those section boundaries, keeping the
 * "Space → Ancestor → H1 → H2" breadcrumb on `heading_path` so
 * citations resolve back to the right page region.
 *
 * The page-properties macro (when present) emits a synthetic preamble
 * chunk with `metadata.page_property_panel = true` — same convention
 * as NotionBlockChunker so the reranker's preamble-match signal works
 * uniformly across vendors.
 *
 * Non-textual `<ac:structured-macro>` blocks (jira-issues, gallery,
 * etc.) are dropped at conversion-time by the Confluence-to-markdown
 * converter, not here. This chunker assumes the converter has already
 * stripped them — it operates on plain markdown.
 */
final class ConfluencePageChunker implements ChunkerInterface
{
    private const SUPPORTED_SOURCE_TYPES = ['confluence'];
    private const TARGET_TOKENS_DEFAULT = 500;
    private const HARD_CAP_DEFAULT = 1024;
    private const PARAGRAPH_SEP = '/\n{2,}/';

    private TokenCounter $tokens;
    private DerivedMetadataReader $reader;

    public function __construct(?TokenCounter $tokens = null, ?DerivedMetadataReader $reader = null)
    {
        $this->tokens = $tokens ?? new TokenCounter();
        $this->reader = $reader ?? new DerivedMetadataReader();
    }

    public function name(): string
    {
        return 'confluence-page-chunker';
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
        $derived = $this->reader->read($doc);
        $sourceMeta = $this->sourceMetadata($doc);
        $filename = $this->resolveFilename($doc->extractionMeta['filename'] ?? null);
        $target = $this->targetTokens();
        $hardCap = $this->hardCapTokens();
        $ancestor = $this->ancestorPath($sourceMeta);

        $drafts = [];
        $propertyChunk = $this->buildPropertyPanelChunk($sourceMeta, $derived, $filename, $ancestor);
        if ($propertyChunk !== null) {
            $drafts[] = $propertyChunk;
        }

        $body = $this->stripFrontmatter($doc->markdown);
        foreach ($this->splitByHierarchy($body, $ancestor, $target, $hardCap) as $segment) {
            $drafts[] = new ChunkDraft(
                text: $segment['text'],
                order: count($drafts),
                headingPath: $segment['heading_path'],
                metadata: [
                    'filename' => $filename,
                    'strategy' => 'confluence-page-hierarchy',
                    'source_type' => 'confluence',
                    'page_block_path' => $segment['heading_path'],
                    'page_property_panel' => false,
                    'search_tags' => $derived['search_tags'],
                    'status_active' => $derived['status_active'],
                    'recency_bucket' => $derived['recency_bucket'],
                    'owner' => $derived['owner'],
                    'space_key' => $sourceMeta['space_key'] ?? null,
                ],
            );
        }

        return $drafts;
    }

    /**
     * @param  array<string,mixed>  $sourceMeta
     * @param  array{search_tags: list<string>, status_active: bool, recency_bucket: string|null, owner: string|null}  $derived
     */
    private function buildPropertyPanelChunk(array $sourceMeta, array $derived, string $filename, string $ancestor): ?ChunkDraft
    {
        $space = $sourceMeta['space_key'] ?? null;
        $labels = $sourceMeta['labels'] ?? [];
        $version = $sourceMeta['version'] ?? null;
        if ($space === null && (! is_array($labels) || $labels === []) && $version === null) {
            return null;
        }
        $lines = [];
        if ($space !== null) {
            $lines[] = "Space: {$space}";
        }
        if (is_array($labels) && $labels !== []) {
            $lines[] = 'Labels: ' . implode(', ', array_filter(
                array_map(static fn ($l) => is_string($l) ? $l : '', $labels),
                static fn (string $l): bool => $l !== '',
            ));
        }
        if ($version !== null) {
            $lines[] = "Version: {$version}";
        }
        $text = trim(implode("\n", $lines));
        if ($text === '') {
            return null;
        }

        $heading = $ancestor === '' ? 'Page properties' : $ancestor . ' > Page properties';
        return new ChunkDraft(
            text: $text,
            order: 0,
            headingPath: $heading,
            metadata: [
                'filename' => $filename,
                'strategy' => 'confluence-page-hierarchy',
                'source_type' => 'confluence',
                'page_block_path' => $heading,
                'page_property_panel' => true,
                'search_tags' => $derived['search_tags'],
                'status_active' => $derived['status_active'],
                'recency_bucket' => $derived['recency_bucket'],
                'owner' => $derived['owner'],
                'space_key' => $space,
            ],
        );
    }

    /**
     * @return list<array{text: string, heading_path: string}>
     */
    private function splitByHierarchy(string $body, string $ancestor, int $target, int $hardCap): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        $segments = [];
        $headingStack = [];
        $buffer = '';
        $bufferHeading = $ancestor;
        $lines = preg_split('/\r?\n/', $body) ?: [];
        $inFence = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line) === 1) {
                $inFence = ! $inFence;
                $buffer .= $line . "\n";
                continue;
            }
            if (! $inFence && preg_match('/^(#{1,4})\s+(.*?)\s*$/', $line, $m) === 1) {
                if (trim($buffer) !== '') {
                    foreach ($this->finalize($buffer, $bufferHeading, $target, $hardCap) as $seg) {
                        $segments[] = $seg;
                    }
                    $buffer = '';
                }
                $level = strlen($m[1]);
                $headingStack = array_slice($headingStack, 0, $level - 1);
                $headingStack[$level - 1] = trim($m[2]);
                $compact = array_filter($headingStack, static fn ($h) => $h !== '' && $h !== null);
                $hierarchyPath = implode(' > ', $compact);
                $bufferHeading = $ancestor === '' ? $hierarchyPath : $ancestor . ' > ' . $hierarchyPath;
                continue;
            }
            $buffer .= $line . "\n";
        }
        if (trim($buffer) !== '') {
            foreach ($this->finalize($buffer, $bufferHeading, $target, $hardCap) as $seg) {
                $segments[] = $seg;
            }
        }
        return $segments;
    }

    /**
     * @return list<array{text: string, heading_path: string}>
     */
    private function finalize(string $body, string $heading, int $target, int $hardCap): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }
        if ($this->tokens->estimate($body) <= $hardCap) {
            return [['text' => $body, 'heading_path' => $heading]];
        }

        $paragraphs = preg_split(self::PARAGRAPH_SEP, $body) ?: [];
        $out = [];
        $buf = '';
        foreach ($paragraphs as $para) {
            $trimmed = trim($para);
            if ($trimmed === '') {
                continue;
            }
            $candidate = $buf === '' ? $trimmed : $buf . "\n\n" . $trimmed;
            if ($this->tokens->estimate($candidate) <= $target) {
                $buf = $candidate;
                continue;
            }
            if ($buf !== '') {
                $out[] = ['text' => $buf, 'heading_path' => $heading];
            }
            $buf = $trimmed;
        }
        if ($buf !== '') {
            $out[] = ['text' => $buf, 'heading_path' => $heading];
        }
        return $out === [] ? [['text' => $body, 'heading_path' => $heading]] : $out;
    }

    /**
     * @param  array<string,mixed>  $sourceMeta
     */
    private function ancestorPath(array $sourceMeta): string
    {
        $ancestors = $sourceMeta['ancestor_titles'] ?? [];
        if (! is_array($ancestors)) {
            return '';
        }
        $clean = array_values(array_filter(
            array_map(static fn ($a) => is_string($a) ? trim($a) : '', $ancestors),
            static fn (string $a): bool => $a !== '',
        ));
        if ($clean === []) {
            return '';
        }
        return implode(' > ', $clean);
    }

    private function stripFrontmatter(string $markdown): string
    {
        $stripped = preg_replace('/\A---\r?\n.*?\r?\n---\r?\n?/s', '', $markdown, 1);
        return $stripped ?? $markdown;
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceMetadata(ConvertedDocument $doc): array
    {
        $meta = $doc->extractionMeta['confluence'] ?? null;
        return is_array($meta) ? $meta : [];
    }

    private function resolveFilename(mixed $raw): string
    {
        if (! is_string($raw)) {
            return 'unknown.confluence.md';
        }
        $trimmed = trim($raw);
        return $trimmed === '' ? 'unknown.confluence.md' : $trimmed;
    }

    private function targetTokens(): int
    {
        return (int) $this->configValue('kb.chunking.target_tokens', self::TARGET_TOKENS_DEFAULT);
    }

    private function hardCapTokens(): int
    {
        return (int) $this->configValue('kb.chunking.hard_cap_tokens', self::HARD_CAP_DEFAULT);
    }

    private function configValue(string $key, int $default): int
    {
        if (! function_exists('config') || ! function_exists('app')) {
            return $default;
        }
        try {
            if (! app()->bound('config')) {
                return $default;
            }
        } catch (\Throwable) {
            return $default;
        }
        return (int) config($key, $default);
    }
}
