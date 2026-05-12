<?php

declare(strict_types=1);

namespace App\Support\Kb;

/**
 * Typed enum for the v3 ingestion pipeline source-type token (T1.8).
 *
 * The string values match the pre-T1.8 convention used by
 * `config/kb-pipeline.php::mime_to_source_type` and the `source_type`
 * column on `knowledge_documents`. The enum is HELPER-ONLY:
 *  - Existing rows and config values continue to use the same string
 *    tokens.
 *  - Helper code (controllers, jobs, the folder walker) converts those
 *    stable tokens to/from `SourceType::*` cases when deriving MIME,
 *    extension, or binary/text handling.
 *  - The KnowledgeDocument model does NOT cast `source_type` to this
 *    enum — adding that cast would change the read shape from string
 *    to enum and break the ~12 existing consumers (admin UI, search
 *    queries, MCP tools) that rely on the string value. See LESSONS
 *    T1.8 rule 1 for the rationale behind the helper-only approach.
 *
 * Use `fromMime()` to derive the enum from an HTTP/file-detected MIME
 * type, `fromExtension()` to derive it from a file extension (used by
 * `KbIngestFolderCommand` when walking the disk), `toMime()` to recover
 * the canonical MIME, `supportedMimes()` to enumerate every accepted
 * MIME including aliases, and `isBinary()` to decide whether the
 * pipeline must read/transmit the source bytes as binary (PDF/DOCX) or
 * as text (markdown/text).
 */
enum SourceType: string
{
    case MARKDOWN = 'markdown';
    case TEXT = 'text';
    case PDF = 'pdf';
    case DOCX = 'docx';
    // v4.5/W5.5 — source-aware connector tokens. Aligned with
    // `config/kb-pipeline.php::mime_to_source_type` so log lines,
    // admin filters, and `knowledge_documents.source_type` rows all
    // agree on the wire string.
    case NOTION = 'notion';
    case NOTION_NOTE = 'notion_note';
    case CONFLUENCE = 'confluence';
    case EVERNOTE = 'evernote';
    case FABRIC = 'fabric';
    case DRIVE_GDOC = 'drive_gdoc';
    case DRIVE_GSHEET = 'drive_gsheet';
    case DRIVE_GSLIDE = 'drive_gslide';
    case ONEDRIVE_OFFICE = 'onedrive_office';
    case UNKNOWN = 'unknown';

    /**
     * Maps a MIME type to the matching SourceType. Returns `UNKNOWN` for
     * MIME types not registered in the pipeline (callers usually treat
     * UNKNOWN as a hard error so the operator notices the gap and adds
     * a converter + mapping).
     *
     * Normalisation: MIME types are case-insensitive and frequently arrive
     * with media-type parameters (e.g. `text/plain; charset=utf-8`). We
     * lowercase + split on `;` so all call sites get consistent routing
     * regardless of header casing or the presence of charset/boundary
     * parameters.
     */
    public static function fromMime(string $mimeType): self
    {
        $normalizedMimeType = strtolower(trim(explode(';', $mimeType, 2)[0]));

        return match ($normalizedMimeType) {
            'text/markdown', 'text/x-markdown' => self::MARKDOWN,
            'text/plain' => self::TEXT,
            'application/pdf' => self::PDF,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => self::DOCX,
            // v4.5/W5.5 vendor MIMEs.
            'application/vnd.notion.page+json' => self::NOTION,
            'application/vnd.notion.note+json' => self::NOTION_NOTE,
            'application/vnd.confluence.page+json' => self::CONFLUENCE,
            'application/vnd.evernote.note+xml' => self::EVERNOTE,
            'application/vnd.fabric.note+json' => self::FABRIC,
            'application/vnd.google-apps.document' => self::DRIVE_GDOC,
            'application/vnd.google-apps.spreadsheet' => self::DRIVE_GSHEET,
            'application/vnd.google-apps.presentation' => self::DRIVE_GSLIDE,
            'application/vnd.onedrive.office+json' => self::ONEDRIVE_OFFICE,
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

    /**
     * @return list<string>  every accepted MIME type across every
     *                       non-UNKNOWN SourceType, INCLUDING aliases
     *                       like `text/x-markdown`. Used by
     *                       KbIngestController to render the actionable
     *                       422 error message ("Supported: ...") so
     *                       operators see the full set of MIMEs the
     *                       endpoint accepts (not just the canonical
     *                       form returned by `toMime()`).
     */
    public static function supportedMimes(): array
    {
        return [
            'text/markdown',
            'text/x-markdown',
            'text/plain',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }
}
