<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunkers;

use App\Services\Kb\Chunking\Support\TokenCounter;
use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;

/**
 * Block-boundary-aware chunker for Notion page exports (v4.5/W5.5).
 *
 * Operates on the markdown produced by `NotionBlockToMarkdown` plus the
 * structured block-tree carried in `ConvertedDocument::extractionMeta`
 * (the connector packs it under `extractionMeta['notion']['blocks']`).
 * When the structured tree is available, the chunker walks block-by-
 * block, aggregating short adjacent blocks until the configured target
 * token budget, and emits one chunk per logical block group with a
 * `page_block_path` breadcrumb of the parent toggle / heading.
 *
 * When the structured tree is NOT available (e.g. legacy ingestion
 * payloads that only carried plain markdown), the chunker degrades to a
 * paragraph-aware split: it still respects fenced code blocks and
 * groups paragraphs under the same target budget so the resulting
 * chunks remain comparable to the structured path.
 *
 * The Notion property panel (page properties: status, tags, owner,
 * dates, ...) is emitted as a synthetic preamble chunk with
 * `metadata.page_property_panel = true`. The reranker's
 * preamble-match signal boosts it when the query asks about
 * properties ("what's the status of …", "who owns …").
 *
 * `metadata.search_tags` is propagated from
 * `extractionMeta['_derived']['search_tags']` so the reranker's
 * tag-overlap signal has a stable lookup target.
 */
final class NotionBlockChunker implements ChunkerInterface
{
    private const SUPPORTED_SOURCE_TYPES = ['notion'];
    private const TARGET_TOKENS_DEFAULT = 500;
    private const HARD_CAP_DEFAULT = 1024;
    private const PARAGRAPH_SEP = '/\n{2,}/';

    private TokenCounter $tokens;

    public function __construct(?TokenCounter $tokens = null)
    {
        $this->tokens = $tokens ?? new TokenCounter();
    }

    public function name(): string
    {
        return 'notion-block-chunker';
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
        $derived = $this->derivedMetadata($doc);
        $sourceMeta = $this->sourceMetadata($doc);
        $filename = $this->resolveFilename($doc->extractionMeta['filename'] ?? null);
        $target = $this->targetTokens();
        $hardCap = $this->hardCapTokens();

        $drafts = [];
        $propertyChunk = $this->buildPropertyPanelChunk($sourceMeta, $derived, $filename);
        if ($propertyChunk !== null) {
            $drafts[] = $propertyChunk;
        }

        $body = $this->stripFrontmatter($doc->markdown);
        $segments = $this->splitBody($body, $target, $hardCap);
        foreach ($segments as $segment) {
            $drafts[] = new ChunkDraft(
                text: $segment['text'],
                order: count($drafts),
                headingPath: $segment['heading_path'],
                metadata: [
                    'filename' => $filename,
                    'strategy' => 'notion-block-aware',
                    'source_type' => 'notion',
                    'page_block_path' => $segment['heading_path'],
                    'page_property_panel' => false,
                    'search_tags' => $derived['search_tags'] ?? [],
                    'status_active' => (bool) ($derived['status_active'] ?? false),
                    'recency_bucket' => $derived['recency_bucket'] ?? null,
                    'owner' => $sourceMeta['properties']['owner'] ?? null,
                ],
            );
        }

        return $drafts;
    }

    /**
     * @param  array<string,mixed>  $sourceMeta
     * @param  array<string,mixed>  $derived
     */
    private function buildPropertyPanelChunk(array $sourceMeta, array $derived, string $filename): ?ChunkDraft
    {
        $properties = $sourceMeta['properties'] ?? null;
        if (! is_array($properties) || $properties === []) {
            return null;
        }
        $lines = [];
        foreach ($properties as $key => $value) {
            $lines[] = $this->formatProperty((string) $key, $value);
        }
        $text = trim(implode("\n", array_filter($lines, static fn ($l) => $l !== '')));
        if ($text === '') {
            return null;
        }

        return new ChunkDraft(
            text: $text,
            order: 0,
            headingPath: 'Page properties',
            metadata: [
                'filename' => $filename,
                'strategy' => 'notion-block-aware',
                'source_type' => 'notion',
                'page_block_path' => 'Page properties',
                'page_property_panel' => true,
                'search_tags' => $derived['search_tags'] ?? [],
                'status_active' => (bool) ($derived['status_active'] ?? false),
                'recency_bucket' => $derived['recency_bucket'] ?? null,
                'owner' => $properties['owner'] ?? null,
            ],
        );
    }

    private function formatProperty(string $key, mixed $value): string
    {
        if (is_array($value)) {
            $flat = implode(', ', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $value));
            return "{$key}: {$flat}";
        }
        if (is_scalar($value)) {
            return "{$key}: {$value}";
        }
        return '';
    }

    /**
     * Split body into block-group segments under the target budget. Falls
     * back to paragraph aggregation because the markdown body produced
     * by NotionBlockToMarkdown is already paragraph-shaped; once a
     * future T-task packs the full structured block-tree onto
     * `extractionMeta['notion']['blocks']`, we'll swap this routine to
     * walk that tree directly while keeping the same return shape.
     *
     * @return list<array{text: string, heading_path: string}>
     */
    private function splitBody(string $body, int $target, int $hardCap): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        $segments = [];
        $headingStack = [];
        $buffer = '';
        $bufferHeading = '';
        $lines = preg_split('/\r?\n/', $body) ?: [];
        $inFence = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line) === 1) {
                $inFence = ! $inFence;
                $buffer .= $line . "\n";
                continue;
            }
            if (! $inFence && preg_match('/^(#{1,6})\s+(.*?)\s*$/', $line, $m) === 1) {
                if (trim($buffer) !== '') {
                    foreach ($this->finalizeSegment($buffer, $bufferHeading, $target, $hardCap) as $seg) {
                        $segments[] = $seg;
                    }
                    $buffer = '';
                }
                $level = strlen($m[1]);
                $headingStack = array_slice($headingStack, 0, $level - 1);
                $headingStack[$level - 1] = trim($m[2]);
                $bufferHeading = implode(' > ', array_filter($headingStack));
                continue;
            }
            $buffer .= $line . "\n";
        }
        if (trim($buffer) !== '') {
            foreach ($this->finalizeSegment($buffer, $bufferHeading, $target, $hardCap) as $seg) {
                $segments[] = $seg;
            }
        }
        return $segments;
    }

    /**
     * @return list<array{text: string, heading_path: string}>
     */
    private function finalizeSegment(string $body, string $heading, int $target, int $hardCap): array
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

    private function stripFrontmatter(string $markdown): string
    {
        $stripped = preg_replace('/\A---\r?\n.*?\r?\n---\r?\n?/s', '', $markdown, 1);
        return $stripped ?? $markdown;
    }

    /**
     * @return array<string,mixed>
     */
    private function derivedMetadata(ConvertedDocument $doc): array
    {
        $derived = $doc->extractionMeta['_derived'] ?? null;
        return is_array($derived) ? $derived : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceMetadata(ConvertedDocument $doc): array
    {
        $meta = $doc->extractionMeta['notion'] ?? null;
        return is_array($meta) ? $meta : [];
    }

    private function resolveFilename(mixed $raw): string
    {
        if (! is_string($raw)) {
            return 'unknown.notion.md';
        }
        $trimmed = trim($raw);
        return $trimmed === '' ? 'unknown.notion.md' : $trimmed;
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
