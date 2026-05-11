<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunkers;

use App\Services\Kb\Chunking\Support\DerivedMetadataReader;
use App\Services\Kb\Chunking\Support\TokenCounter;
use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;

/**
 * Office-format dispatcher chunker (v4.5/W5.5).
 *
 * Handles the structured-Office source-type family produced by the
 * Google Drive / OneDrive connectors when an authored document is
 * exported through their native APIs. Different formats need different
 * strategies; this class owns the dispatch:
 *
 *   - `drive_gdoc`         → markdown-section split (Google exports
 *                            Docs to markdown; same shape as
 *                            MarkdownChunker but tagged with the
 *                            source-aware metadata namespace).
 *   - `drive_gsheet`       → row-window chunks (10 rows per chunk,
 *                            with the header row prepended as context).
 *   - `drive_gslide`       → DEFERRED to v4.6. Returns a single chunk
 *                            with a `metadata.skip_reason = "gslide-deferred"`
 *                            marker so the document is still indexed
 *                            (refusal-not-error UX) but operators can
 *                            grep for the marker to plan the migration.
 *   - `onedrive_office`    → markdown-section split (OneDrive exports
 *                            Office docs through the same docx-style
 *                            markdown shape).
 *
 * The dispatch key is the `source_type` token resolved by the registry
 * (which itself is derived from the source MIME type set by the
 * connector). This chunker INTENTIONALLY exposes a single
 * `supports()` that claims the full family so the registry has a
 * single hand-off point.
 */
final class OfficeDocChunker implements ChunkerInterface
{
    public const SUPPORTED_SOURCE_TYPES = [
        'drive_gdoc',
        'drive_gsheet',
        'drive_gslide',
        'onedrive_office',
    ];
    private const TARGET_TOKENS_DEFAULT = 500;
    private const HARD_CAP_DEFAULT = 1024;
    private const PARAGRAPH_SEP = '/\n{2,}/';
    private const SHEET_ROWS_PER_CHUNK = 10;

    private TokenCounter $tokens;
    private DerivedMetadataReader $reader;

    public function __construct(?TokenCounter $tokens = null, ?DerivedMetadataReader $reader = null)
    {
        $this->tokens = $tokens ?? new TokenCounter();
        $this->reader = $reader ?? new DerivedMetadataReader();
    }

    public function name(): string
    {
        return 'office-doc-chunker';
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
        $sourceType = (string) ($doc->extractionMeta['source_type'] ?? '');
        $derived = $this->reader->read($doc);
        $filename = $this->resolveFilename($doc->extractionMeta['filename'] ?? null, $sourceType);

        return match ($sourceType) {
            'drive_gsheet' => $this->chunkSpreadsheet($doc, $derived, $filename),
            'drive_gslide' => $this->chunkSlidesDeferred($doc, $derived, $filename),
            default => $this->chunkMarkdownSections($doc, $derived, $filename, $sourceType),
        };
    }

    /**
     * @param  array{search_tags: list<string>, status_active: bool, recency_bucket: string|null, owner: string|null}  $derived
     * @return list<ChunkDraft>
     */
    private function chunkMarkdownSections(ConvertedDocument $doc, array $derived, string $filename, string $sourceType): array
    {
        $body = $this->stripFrontmatter($doc->markdown);
        $segments = $this->splitMarkdown($body, $this->targetTokens(), $this->hardCapTokens());
        $effectiveType = $sourceType !== '' ? $sourceType : 'drive_gdoc';

        $drafts = [];
        foreach ($segments as $idx => $segment) {
            $drafts[] = new ChunkDraft(
                text: $segment['text'],
                order: $idx,
                headingPath: $segment['heading_path'],
                metadata: [
                    'filename' => $filename,
                    'strategy' => 'office-doc-markdown',
                    'source_type' => $effectiveType,
                    'page_block_path' => $segment['heading_path'],
                    'page_property_panel' => false,
                    'search_tags' => $derived['search_tags'],
                    'status_active' => $derived['status_active'],
                    'recency_bucket' => $derived['recency_bucket'],
                    'owner' => $derived['owner'],
                ],
            );
        }
        return $drafts;
    }

    /**
     * @param  array{search_tags: list<string>, status_active: bool, recency_bucket: string|null, owner: string|null}  $derived
     * @return list<ChunkDraft>
     */
    private function chunkSpreadsheet(ConvertedDocument $doc, array $derived, string $filename): array
    {
        $rows = $this->extractRows($doc);
        if ($rows === []) {
            return [];
        }
        $header = (array) array_shift($rows);
        $headerLine = implode("\t", array_map(static fn ($c) => (string) $c, $header));

        $drafts = [];
        $order = 0;
        foreach (array_chunk($rows, self::SHEET_ROWS_PER_CHUNK) as $window) {
            $lines = [];
            foreach ($window as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lines[] = implode("\t", array_map(static fn ($c) => is_scalar($c) ? (string) $c : '', $row));
            }
            $text = trim($headerLine . "\n" . implode("\n", $lines));
            if ($text === '') {
                continue;
            }
            $headingPath = 'Rows ' . ($order * self::SHEET_ROWS_PER_CHUNK + 1) . '-' . ($order * self::SHEET_ROWS_PER_CHUNK + count($window));
            $drafts[] = new ChunkDraft(
                text: $text,
                order: $order++,
                headingPath: $headingPath,
                metadata: [
                    'filename' => $filename,
                    'strategy' => 'office-doc-spreadsheet',
                    'source_type' => 'drive_gsheet',
                    'page_block_path' => $headingPath,
                    'page_property_panel' => false,
                    'search_tags' => $derived['search_tags'],
                    'status_active' => $derived['status_active'],
                    'recency_bucket' => $derived['recency_bucket'],
                    'owner' => $derived['owner'],
                ],
            );
        }
        return $drafts;
    }

    /**
     * Deferred slide handling — v4.6 will add a slide-render → image OCR
     * pipeline. Until then we emit ONE diagnostic chunk so the document
     * row still gets created (refusal-not-error: caller can grep
     * `skip_reason=gslide-deferred` to find docs awaiting the upgrade).
     *
     * @param  array{search_tags: list<string>, status_active: bool, recency_bucket: string|null, owner: string|null}  $derived
     * @return list<ChunkDraft>
     */
    private function chunkSlidesDeferred(ConvertedDocument $doc, array $derived, string $filename): array
    {
        $text = trim($this->stripFrontmatter($doc->markdown));
        if ($text === '') {
            $text = '(Google Slides body extraction deferred to v4.6 — '
                . 'slide-text extraction requires a render pipeline not yet shipped.)';
        }
        return [new ChunkDraft(
            text: $text,
            order: 0,
            headingPath: 'Slides (deferred)',
            metadata: [
                'filename' => $filename,
                'strategy' => 'office-doc-slides-deferred',
                'source_type' => 'drive_gslide',
                'page_block_path' => 'Slides (deferred)',
                'page_property_panel' => false,
                'skip_reason' => 'gslide-deferred',
                'search_tags' => $derived['search_tags'],
                'status_active' => $derived['status_active'],
                'recency_bucket' => $derived['recency_bucket'],
                'owner' => $derived['owner'],
            ],
        )];
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function extractRows(ConvertedDocument $doc): array
    {
        $rows = $doc->extractionMeta['rows'] ?? null;
        if (! is_array($rows)) {
            return [];
        }
        return array_values($rows);
    }

    /**
     * @return list<array{text: string, heading_path: string}>
     */
    private function splitMarkdown(string $body, int $target, int $hardCap): array
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
                    foreach ($this->finalize($buffer, $bufferHeading, $target, $hardCap) as $seg) {
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

    private function stripFrontmatter(string $markdown): string
    {
        $stripped = preg_replace('/\A---\r?\n.*?\r?\n---\r?\n?/s', '', $markdown, 1);
        return $stripped ?? $markdown;
    }

    private function resolveFilename(mixed $raw, string $sourceType): string
    {
        $fallback = match ($sourceType) {
            'drive_gsheet' => 'unknown.gsheet',
            'drive_gslide' => 'unknown.gslide',
            'onedrive_office' => 'unknown.office',
            default => 'unknown.gdoc.md',
        };
        if (! is_string($raw)) {
            return $fallback;
        }
        $trimmed = trim($raw);
        return $trimmed === '' ? $fallback : $trimmed;
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
