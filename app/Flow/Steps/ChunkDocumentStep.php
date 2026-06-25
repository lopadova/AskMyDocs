<?php

declare(strict_types=1);

namespace App\Flow\Steps;

use App\Services\Kb\Pii\ChunkRedactor;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\PipelineRegistry;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 2 of {@see \App\Flow\Definitions\IngestDocumentFlow}.
 *
 * Resolves the Chunker registered for the source-type token (driven by
 * the MIME→source-type mapping in `config/kb-pipeline.php`) and produces
 * a list of {@see \App\Services\Kb\Pipeline\ChunkDraft} structures, each
 * carrying chunk text + heading_path + chunk-order.
 *
 * Dry-run: chunks in memory and reports counts, but does not persist —
 * the persist step skips itself in dry-run as a defence in depth.
 */
final class ChunkDocumentStep implements FlowStepHandler
{
    public function __construct(
        private readonly PipelineRegistry $registry,
        private readonly ChunkRedactor $redactor,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        $parseOutput = $context->stepOutputs['parse-markdown'] ?? null;
        if (! is_array($parseOutput)) {
            throw new RuntimeException(
                'ChunkDocumentStep: missing prior step output [parse-markdown].'
            );
        }

        $mimeType = (string) ($parseOutput['mime_type'] ?? '');
        $markdown = (string) ($parseOutput['markdown'] ?? '');
        $mediaItems = is_array($parseOutput['media_items'] ?? null)
            ? $parseOutput['media_items']
            : [];
        $extractionMeta = is_array($parseOutput['extraction_meta'] ?? null)
            ? $parseOutput['extraction_meta']
            : [];

        $sourceType = $this->resolveSourceType($mimeType);

        $converted = new ConvertedDocument(
            markdown: $markdown,
            mediaItems: $mediaItems,
            extractionMeta: $extractionMeta,
            sourceMimeType: $mimeType,
        );

        $chunker = $this->registry->resolveChunker($sourceType);
        $chunkDrafts = $chunker->chunk($converted);

        // v8.23 (Ciclo 4) — PII redaction of the chunk text HERE, so the
        // downstream embed-chunks + persist-chunks steps (which both read
        // `chunk_drafts`) only ever see surrogates — the real HTTP/CLI ingest
        // path runs through this Flow, not DocumentIngestor::ingest(). On a
        // dry-run the redactor forces the side-effect-free mask so the preview /
        // flow-audit never stores raw PII and never mints vault tokens. No-op
        // unless redaction is genuinely active (gates + per-project policy).
        $projectKey = (string) ($context->input['project_key'] ?? '');
        $chunkDrafts = $this->redactor->redact($projectKey, $chunkDrafts, previewSafe: $context->dryRun);

        $serialized = array_map(
            static fn ($draft): array => [
                'text' => $draft->text,
                'order' => $draft->order,
                'heading_path' => $draft->headingPath,
                'metadata' => $draft->metadata,
            ],
            $chunkDrafts,
        );

        $impact = [
            'source_type' => $sourceType,
            'chunk_count' => count($serialized),
        ];

        $output = [
            'source_type' => $sourceType,
            'chunk_drafts' => $serialized,
        ];

        if ($context->dryRun) {
            return new FlowStepResult(
                success: true,
                output: $output,
                businessImpact: $impact,
            );
        }

        return FlowStepResult::success($output, $impact);
    }

    private function resolveSourceType(string $mimeType): string
    {
        /** @var array<string, mixed> $map */
        $map = (array) config('kb-pipeline.mime_to_source_type', []);
        if (! array_key_exists($mimeType, $map)) {
            throw new RuntimeException(sprintf(
                'Missing MIME→source-type mapping for "%s". Add it to config/kb-pipeline.php under "mime_to_source_type".',
                $mimeType,
            ));
        }
        return (string) $map[$mimeType];
    }
}
