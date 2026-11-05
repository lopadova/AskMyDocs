<?php

declare(strict_types=1);

namespace App\Flow\Steps;

use App\Services\Kb\Embedding\EmbeddingInputTemplate;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Pipeline\ChunkDraft;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 3 of {@see \App\Flow\Definitions\IngestDocumentFlow}.
 *
 * Generates embeddings for every chunk via {@see EmbeddingCacheService}.
 * Cache hits are free; misses go to the configured embeddings provider.
 *
 * NOT dry-run-safe — embeddings cost money even on cache misses, so the
 * Flow definition does NOT enable `withDryRun(true)` for this step. In a
 * dry-run execution the engine sends a {@see FlowStepResult::dryRunSkipped()}
 * marker, which is correctly interpreted as "this step was not executed".
 * Persist + canonical-indexer downstream will likewise skip themselves.
 */
final class EmbedChunksStep implements FlowStepHandler
{
    public function __construct(
        private readonly EmbeddingCacheService $embeddings,
        private readonly EmbeddingInputTemplate $template,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            // Defence-in-depth: even though the definition does not opt
            // this step in, the engine could be re-entered via a future
            // dryRun() call. Skip.
            return FlowStepResult::dryRunSkipped();
        }

        $chunkOutput = $context->stepOutputs['chunk-document'] ?? null;
        if (! is_array($chunkOutput)) {
            throw new RuntimeException(
                'EmbedChunksStep: missing prior step output [chunk-document].'
            );
        }

        if (($chunkOutput['source_type'] ?? null) === \App\Connectors\Freshdesk\FreshdeskCaseStudyLineage::SOURCE_TYPE) {
            app(\App\Connectors\Freshdesk\FreshdeskCaseStudyLineage::class)->assertValid((array) ($context->input['metadata'] ?? []), (string) ($context->input['project_key'] ?? ''));
        }

        $drafts = $chunkOutput['chunk_drafts'] ?? [];
        if (! is_array($drafts)) {
            $drafts = [];
        }

        // v8.42/W2 (ADR 0035 §D2) — compose the embedding inputs through the
        // SAME shared template the direct DocumentIngestor path uses (bare
        // chunk text when the contextual flag is off). Header fragments
        // (title / path / headings) are PII-redacted inside the template.
        $parseOutput = is_array($context->stepOutputs['parse-markdown'] ?? null)
            ? $context->stepOutputs['parse-markdown']
            : [];
        $sourcePath = (string) ($parseOutput['source_path'] ?? $context->input['source_path'] ?? '');
        $title = (string) ($context->input['title'] ?? '');
        if ($title === '') {
            $title = pathinfo($sourcePath, PATHINFO_FILENAME);
        }
        $canonicalType = is_array($parseOutput['canonical'] ?? null)
            ? ($parseOutput['canonical']['type'] ?? null)
            : null;

        $draftObjects = array_map(
            static fn (array $draft): ChunkDraft => new ChunkDraft(
                text: (string) ($draft['text'] ?? ''),
                order: (int) ($draft['order'] ?? 0),
                headingPath: (string) ($draft['heading_path'] ?? ''),
                metadata: is_array($draft['metadata'] ?? null) ? $draft['metadata'] : [],
            ),
            $drafts,
        );

        $texts = $this->template->inputs(
            (string) ($context->input['project_key'] ?? ''),
            $title,
            $sourcePath,
            is_string($canonicalType) ? $canonicalType : null,
            $draftObjects,
        );

        $response = $this->embeddings->generate($texts);

        $output = [
            'embeddings' => $response->embeddings,
            'provider' => $response->provider,
            'model' => $response->model,
            'total_tokens' => $response->totalTokens,
        ];

        $impact = [
            'embedding_count' => count($response->embeddings),
            'provider' => $response->provider,
            'model' => $response->model,
        ];

        return FlowStepResult::success($output, $impact);
    }
}
