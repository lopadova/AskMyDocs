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
        // DocxConverter added in T1.6
    ],

    /**
     * @var class-string<\App\Services\Kb\Contracts\ChunkerInterface>[]
     */
    'chunkers' => [
        \App\Services\Kb\MarkdownChunker::class,
        // PdfPageChunker added in T1.7
    ],

    /**
     * @var class-string<\App\Services\Kb\Contracts\EnricherInterface>[]
     */
    'enrichers' => [
        // populated in v3.1
    ],

    /**
     * MIME-type → source-type token mapping. The source-type drives Chunker
     * resolution and lands on `knowledge_documents.source_type` (T1.8 will
     * promote this string to a typed enum).
     *
     * @var array<string, string>
     */
    'mime_to_source_type' => [
        'text/markdown'   => 'markdown',
        'text/x-markdown' => 'markdown',
        'text/plain'      => 'text',
        'application/pdf' => 'pdf',
        // T1.6 adds: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
    ],
];
