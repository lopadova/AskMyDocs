<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunkers;

use App\Services\Kb\Chunking\Support\DerivedMetadataReader;
use App\Services\Kb\Chunking\Support\TokenCounter;
use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;

/**
 * Atomic-note chunker for short, tag-driven sources (v4.5/W5.5).
 *
 * Notes from Evernote / Fabric / short Notion-note exports tend to be
 * single-topic, sub-1000-token documents. Splitting them on heading
 * boundaries loses the topical coherence the source already enforced.
 * This chunker emits ONE chunk per note when the body fits the hard
 * cap, and falls back to H2-based section splits only when the body
 * exceeds the budget.
 *
 * Tags and notebook are pulled from `_derived.search_tags` so the
 * reranker tag-overlap signal works against them; the per-source
 * namespace (e.g. `evernote.notebook`, `fabric.collection_id`) is
 * preserved on chunk metadata for debugging.
 *
 * source_type tokens claimed:
 *   - `evernote`
 *   - `fabric`
 *   - `notion_note` (short Notion notes — distinct from full Notion
 *      pages routed to NotionBlockChunker)
 */
final class AtomicNoteChunker implements ChunkerInterface
{
    public const SUPPORTED_SOURCE_TYPES = ['evernote', 'fabric', 'notion_note'];
    private const TARGET_TOKENS_DEFAULT = 800;
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
        return 'atomic-note-chunker';
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
        $sourceType = (string) ($doc->extractionMeta['source_type'] ?? '');
        $filename = $this->resolveFilename($doc->extractionMeta['filename'] ?? null, $sourceType);
        $sourceMeta = $this->sourceMetadata($doc, $sourceType);

        $body = trim($this->stripFrontmatter($doc->markdown));
        if ($body === '') {
            return [];
        }

        $target = $this->configValue('kb.chunking.target_tokens', self::TARGET_TOKENS_DEFAULT);
        $hardCap = $this->configValue('kb.chunking.hard_cap_tokens', self::HARD_CAP_DEFAULT);

        // Atomic short-note path — one chunk for the whole body when it fits.
        if ($this->tokens->estimate($body) <= $target) {
            return [new ChunkDraft(
                text: $body,
                order: 0,
                headingPath: $this->extractFirstHeading($body) ?? '',
                metadata: $this->chunkMetadata($filename, $sourceType, $derived, $sourceMeta, '', false),
            )];
        }

        // Long-note fallback — split on H2 boundaries.
        return $this->splitByH2($body, $target, $hardCap, $filename, $sourceType, $derived, $sourceMeta);
    }

    private function extractFirstHeading(string $body): ?string
    {
        $lines = preg_split('/\r?\n/', $body) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.*?)\s*$/', $line, $m) === 1) {
                return trim($m[2]);
            }
        }
        return null;
    }

    /**
     * @param  array{search_tags: list<string>, status_active: bool, recency_bucket: string|null, owner: string|null}  $derived
     * @param  array<string,mixed>  $sourceMeta
     * @return list<ChunkDraft>
     */
    private function splitByH2(
        string $body,
        int $target,
        int $hardCap,
        string $filename,
        string $sourceType,
        array $derived,
        array $sourceMeta,
    ): array {
        $segments = [];
        $headingStack = ['', ''];
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
            if (! $inFence && preg_match('/^(##)\s+(.*?)\s*$/', $line, $m) === 1) {
                if (trim($buffer) !== '') {
                    foreach ($this->finalize($buffer, $bufferHeading, $target, $hardCap) as $seg) {
                        $segments[] = $seg;
                    }
                    $buffer = '';
                }
                $bufferHeading = trim($m[2]);
                continue;
            }
            $buffer .= $line . "\n";
        }
        if (trim($buffer) !== '') {
            foreach ($this->finalize($buffer, $bufferHeading, $target, $hardCap) as $seg) {
                $segments[] = $seg;
            }
        }

        $drafts = [];
        foreach ($segments as $idx => $segment) {
            $drafts[] = new ChunkDraft(
                text: $segment['text'],
                order: $idx,
                headingPath: $segment['heading_path'],
                metadata: $this->chunkMetadata($filename, $sourceType, $derived, $sourceMeta, $segment['heading_path'], false),
            );
        }
        return $drafts;
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
     * @param  array{search_tags: list<string>, status_active: bool, recency_bucket: string|null, owner: string|null}  $derived
     * @param  array<string,mixed>  $sourceMeta
     * @return array<string,mixed>
     */
    private function chunkMetadata(
        string $filename,
        string $sourceType,
        array $derived,
        array $sourceMeta,
        string $headingPath,
        bool $isPropertyPanel,
    ): array {
        $effectiveType = $sourceType !== '' ? $sourceType : 'evernote';
        return [
            'filename' => $filename,
            'strategy' => 'atomic-note',
            'source_type' => $effectiveType,
            'page_block_path' => $headingPath,
            'page_property_panel' => $isPropertyPanel,
            'search_tags' => $derived['search_tags'],
            'status_active' => $derived['status_active'],
            'recency_bucket' => $derived['recency_bucket'],
            'owner' => $derived['owner'],
            'notebook' => $sourceMeta['notebook'] ?? null,
            'collection_id' => $sourceMeta['collection_id'] ?? null,
        ];
    }

    private function stripFrontmatter(string $markdown): string
    {
        $stripped = preg_replace('/\A---\r?\n.*?\r?\n---\r?\n?/s', '', $markdown, 1);
        return $stripped ?? $markdown;
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceMetadata(ConvertedDocument $doc, string $sourceType): array
    {
        $key = match ($sourceType) {
            'fabric' => 'fabric',
            'notion_note' => 'notion',
            default => 'evernote',
        };
        $meta = $doc->extractionMeta[$key] ?? null;
        return is_array($meta) ? $meta : [];
    }

    private function resolveFilename(mixed $raw, string $sourceType): string
    {
        $fallback = match ($sourceType) {
            'fabric' => 'unknown.fabric.md',
            'notion_note' => 'unknown.notion-note.md',
            default => 'unknown.evernote.md',
        };
        if (! is_string($raw)) {
            return $fallback;
        }
        $trimmed = trim($raw);
        return $trimmed === '' ? $fallback : $trimmed;
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
