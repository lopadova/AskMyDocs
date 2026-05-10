<?php

declare(strict_types=1);

namespace App\Flow\Steps\Prune;

use App\Flow\Steps\StepTenantBinder;
use App\Services\Kb\EmbeddingCacheService;
use DateTimeImmutable;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 3 of {@see \App\Flow\Definitions\PruneEmbeddingCacheFlow}.
 *
 * Calls {@see EmbeddingCacheService::prune()} which DELETEs all
 * embedding_cache rows whose `last_used_at` is older than the cutoff.
 * Returns the number of rows actually removed (engine reports it as
 * the run's businessImpact).
 *
 * embedding_cache is intentionally cross-tenant; no `forTenant()` is
 * applied (see {@see CountStaleEmbeddingsStep} class docblock).
 *
 * Dry-run skipped — DB write is the only artefact.
 */
final class EvictEmbeddingCacheStep implements FlowStepHandler
{
    public function __construct(
        private readonly EmbeddingCacheService $cache,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $cutoff = $this->parseCutoff($context->input['cutoff_iso'] ?? null);
        $deleted = $this->cache->prune($cutoff);

        return FlowStepResult::success(
            output: [
                'cutoff_iso' => $cutoff->format(\DateTimeInterface::ATOM),
                'deleted_count' => $deleted,
            ],
            businessImpact: ['deleted_count' => $deleted],
        );
    }

    private function parseCutoff(mixed $raw): DateTimeImmutable
    {
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException(
                'EvictEmbeddingCacheStep: input["cutoff_iso"] must be a non-empty ISO 8601 string.'
            );
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'EvictEmbeddingCacheStep: input["cutoff_iso"] is not a valid ISO 8601 timestamp.',
                previous: $e,
            );
        }
    }
}
