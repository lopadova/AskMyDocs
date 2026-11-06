<?php

declare(strict_types=1);

/**
 * KB ingestion pipeline registry config (v3.0 / T1.4).
 *
 * Lists every Converter, Chunker, and Enricher class the
 * {@see \App\Services\Kb\Pipeline\PipelineRegistry} should boot at runtime.
 * Each list is order-significant — the first implementation that returns
 * `true` from `supports($mime|$sourceType)` wins (so put more specific
 * converters before more permissive ones if there is ever overlap).
 *
 * To add a new file format:
 *   1. Implement the relevant Contract under `App\Services\Kb\Contracts\`
 *   2. Append the FQCN to the matching list below
 *   3. Add the MIME → source-type mapping under `mime_to_source_type`
 *
 * See README.md → "Extending the Ingestion Pipeline" for the full recipe.
 */
return [
    /**
     * @var class-string<\App\Services\Kb\Contracts\ConverterInterface>[]
     */
    'converters' => [
        \App\Services\Kb\Converters\MarkdownPassthroughConverter::class,
        \App\Services\Kb\Converters\TextPassthroughConverter::class,
        // v8.45 / ADR 0035 D7 — source code. Claims only the synthetic
        // `text/x-<language>` MIMEs, so it never overlaps a prose converter;
        // acceptance is gated at the entry points (kb.code.ingestion_enabled).
        \App\Services\Kb\Converters\CodePassthroughConverter::class,
        // v8.36 / ADR 0029 — OCR. Claims `image/*` only when `kb.ocr.enabled`
        // is true (OFF → supports() is false for every MIME, so the registry
        // falls through exactly as before). Scanned PDFs are NOT claimed here:
        // the registry resolves by MIME alone, so the text-layer decision lives
        // as a fallback inside PdfConverter, delegating to the same OcrService.
        \App\Services\Kb\Converters\OcrConverter::class,
        \App\Services\Kb\Converters\PdfConverter::class,
        \App\Services\Kb\Converters\DocxConverter::class,
        \App\Services\Kb\Converters\FreshdeskCaseStudyConverter::class,
        \App\Services\Kb\Converters\VendorMarkdownPassthroughConverter::class,
    ],

    /**
     * @var class-string<\App\Services\Kb\Contracts\ChunkerInterface>[]
     *
     * Order is significant — first match wins. v4.5/W5.5 source-aware
     * chunkers are listed BEFORE the generic MarkdownChunker fallback,
     * and PdfPageChunker stays first for the `pdf` token. The non-overlap
     * invariant is enforced by `PipelineRegistryChunkerMutexTest` so the
     * order is structural, not a hidden ordering trap.
     */
    'chunkers' => [
        \App\Services\Kb\Chunkers\FreshdeskCaseStudyChunker::class,
        \App\Services\Kb\Chunkers\PdfPageChunker::class,
        \App\Services\Kb\Chunkers\NotionBlockChunker::class,
        \App\Services\Kb\Chunkers\ConfluencePageChunker::class,
        \App\Services\Kb\Chunkers\JiraIssueChunker::class,
        \App\Services\Kb\Chunkers\OfficeDocChunker::class,
        \App\Services\Kb\Chunkers\AtomicNoteChunker::class,
        // v8.45 / ADR 0035 D7 — claims the `code` source-type token only.
        \App\Services\Kb\Chunkers\CodeAstChunker::class,
        \App\Services\Kb\MarkdownChunker::class,
    ],

    /**
     * @var class-string<\App\Services\Kb\Contracts\EnricherInterface>[]
     */
    'enrichers' => [
        // populated in v3.1
    ],

    /**
     * MIME-type → source-type token mapping. The source-type drives Chunker
     * resolution and lands on `knowledge_documents.source_type`. Typed
     * handling at the call sites (controllers, jobs, the folder walker)
     * goes through {@see \App\Support\Kb\SourceType} (helper-only enum —
     * the column itself stays string for read back-compat).
     *
     * @var array<string, string>
     */
    'mime_to_source_type' => [
        'application/vnd.askmydocs.freshdesk-case+markdown' => 'freshdesk_case_study',
        'text/markdown'   => 'markdown',
        'text/x-markdown' => 'markdown',
        'text/plain'      => 'text',
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        // v8.36 / ADR 0029 — scanned images (OcrConverter → PdfPageChunker).
        // Static mapping; whether an image is ACCEPTED is decided at the
        // entry points by `kb.ocr.enabled` (SourceType::supportedMimes(true)).
        'image/png'  => 'image',
        'image/jpeg' => 'image',
        'image/tiff' => 'image',
        'image/webp' => 'image',

        // v8.45 / ADR 0035 D7 — source code, one token per language. Static
        // mapping; whether a code file is ACCEPTED is decided at the entry
        // points by `kb.code.ingestion_enabled` (R43). A parity test pins this
        // list to App\Services\Kb\Code\CodeLanguageMap.
        'text/x-php'        => 'code',
        'text/x-typescript' => 'code',
        'text/x-tsx'        => 'code',
        'text/x-javascript' => 'code',
        'text/x-python'     => 'code',
        'text/x-go'         => 'code',
        'text/x-rust'       => 'code',
        'text/x-java'       => 'code',
        'text/x-csharp'     => 'code',
        'text/x-ruby'       => 'code',
        'text/x-sql'        => 'code',
        'text/x-yaml'       => 'code',
        'text/x-json'       => 'code',
        'text/x-bash'       => 'code',

        // v4.5/W5.5 — synthetic vendor MIME tokens. Connectors set the
        // source MIME on the SourceDocument so the registry routes to
        // the right chunker without inventing a new dispatch surface.
        // The corresponding source-type tokens land on
        // `knowledge_documents.source_type` and on the chunk metadata,
        // letting the SPA `facets[source]` filter narrow by provenance.
        'application/vnd.notion.page+json'        => 'notion',
        'application/vnd.notion.note+json'        => 'notion_note',
        'application/vnd.confluence.page+json'    => 'confluence',
        'application/vnd.jira.issue+json'         => 'jira',
        'application/vnd.evernote.note+xml'       => 'evernote',
        'application/vnd.fabric.note+json'        => 'fabric',
        'application/vnd.google-apps.document'    => 'drive_gdoc',
        'application/vnd.google-apps.spreadsheet' => 'drive_gsheet',
        'application/vnd.google-apps.presentation' => 'drive_gslide',
        'application/vnd.onedrive.office+json'    => 'onedrive_office',
    ],
];
