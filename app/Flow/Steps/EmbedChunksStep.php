<?php

declare(strict_types=1);

namespace App\Flow\Steps;

use App\Services\Kb\EmbeddingCacheService;
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

        $drafts = $chunkOutput['chunk_drafts'] ?? [];
        if (! is_array($drafts)) {
            $drafts = [];
        }

        $texts = array_map(
            static fn (array $draft): string => (string) ($draft['text'] ?? ''),
            $drafts,
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
