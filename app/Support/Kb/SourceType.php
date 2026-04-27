<?php

declare(strict_types=1);

namespace App\Support\Kb;

/**
 * Typed enum for the v3 ingestion pipeline source-type token (T1.8).
 *
 * The string values match the pre-T1.8 convention used by
 * `config/kb-pipeline.php::mime_to_source_type` and the `source_type`
 * column on `knowledge_documents`. Keeping the values stable means:
 *  - Existing rows read back into `SourceType::*` cases unchanged.
 *  - The KnowledgeDocument model can cast `source_type` to this enum
 *    transparently.
 *  - Config and CLI/API surfaces still accept/emit the bare string.
 *
 * Use `fromMime()` to derive the enum from an HTTP/file-detected MIME
 * type, `fromExtension()` to derive it from a file extension (used by
 * `KbIngestFolderCommand` when walking the disk), `toMime()` to recover
 * the canonical MIME, and `isBinary()` to decide whether the
 * pipeline must read/transmit the source bytes as binary (PDF/DOCX) or
 * as text (markdown/text).
 */
enum SourceType: string
{
    case MARKDOWN = 'markdown';
    case TEXT = 'text';
    case PDF = 'pdf';
    case DOCX = 'docx';
    case UNKNOWN = 'unknown';

    /**
     * Maps a MIME type to the matching SourceType. Returns `UNKNOWN` for
     * MIME types not registered in the pipeline (callers usually treat
     * UNKNOWN as a hard error so the operator notices the gap and adds
     * a converter + mapping).
     */
    public static function fromMime(string $mimeType): self
    {
        return match ($mimeType) {
            'text/markdown', 'text/x-markdown' => self::MARKDOWN,
            'text/plain' => self::TEXT,
            'application/pdf' => self::PDF,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => self::DOCX,
            default => self::UNKNOWN,
        };
    }

    /**
     * Maps a file extension (with or without leading dot) to the
     * matching SourceType. Used by KbIngestFolderCommand when walking
     * a disk and resolving each file's source type from its extension
     * before dispatching the ingestion job.
     */
    public static function fromExtension(string $extension): self
    {
        return match (strtolower(ltrim($extension, '.'))) {
            'md', 'markdown' => self::MARKDOWN,
            'txt' => self::TEXT,
            'pdf' => self::PDF,
            'docx' => self::DOCX,
            default => self::UNKNOWN,
        };
    }

    /**
     * The canonical MIME type for this source-type. UNKNOWN returns
     * `application/octet-stream` (a deliberate "I don't know" signal
     * that downstream pipeline code can detect and refuse to ingest).
     */
    public function toMime(): string
    {
        return match ($this) {
            self::MARKDOWN => 'text/markdown',
            self::TEXT => 'text/plain',
            self::PDF => 'application/pdf',
            self::DOCX => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::UNKNOWN => 'application/octet-stream',
        };
    }

    /**
     * Returns true for source-types whose bytes are NOT 7-bit clean
     * text (PDF, DOCX). Callers that read from disk or accept bytes
     * over HTTP use this to decide between a text-decoded read and
     * a raw-bytes read; the API layer also uses this to require
     * base64 encoding on `documents.*.content` for binary types.
     */
    public function isBinary(): bool
    {
        return match ($this) {
            self::PDF, self::DOCX => true,
            default => false,
        };
    }

    /**
     * Lower-case string token used in config lists, DB columns, log
     * lines. Equivalent to `->value` but reads more clearly at the
     * call site (`SourceType::PDF->token()` vs `->value`).
     */
    public function token(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>  every supported file extension across all
     *                       SourceTypes EXCEPT UNKNOWN. Used by
     *                       KbIngestFolderCommand as the default `--pattern`
     *                       value so multi-format folder walks find every
     *                       supported file out-of-the-box.
     */
    public static function knownExtensions(): array
    {
        return ['md', 'markdown', 'txt', 'pdf', 'docx'];
    }
}
