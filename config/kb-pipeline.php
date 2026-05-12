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
        \App\Services\Kb\Converters\PdfConverter::class,
        \App\Services\Kb\Converters\DocxConverter::class,
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
        \App\Services\Kb\Chunkers\PdfPageChunker::class,
        \App\Services\Kb\Chunkers\NotionBlockChunker::class,
        \App\Services\Kb\Chunkers\ConfluencePageChunker::class,
        \App\Services\Kb\Chunkers\JiraIssueChunker::class,
        \App\Services\Kb\Chunkers\OfficeDocChunker::class,
        \App\Services\Kb\Chunkers\AtomicNoteChunker::class,
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
        'text/markdown'   => 'markdown',
        'text/x-markdown' => 'markdown',
        'text/plain'      => 'text',
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',

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
