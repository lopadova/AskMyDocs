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
    ],

    /**
     * @var class-string<\App\Services\Kb\Contracts\ChunkerInterface>[]
     *
     * Order is significant — first match wins. PdfPageChunker is listed
     * BEFORE MarkdownChunker so the registry resolves `pdf` source-type
     * to the page-aware chunker even though MarkdownChunker would no
     * longer claim 'pdf' anyway (defence-in-depth).
     */
    'chunkers' => [
        \App\Services\Kb\Chunkers\PdfPageChunker::class,
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
    ],
];
