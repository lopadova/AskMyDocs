<?php

declare(strict_types=1);

namespace App\Flow\Steps\Prune;

use App\Flow\Steps\StepTenantBinder;
use App\Models\EmbeddingCache;
use DateTimeImmutable;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 1 of {@see \App\Flow\Definitions\PruneEmbeddingCacheFlow}.
 *
 * Counts embedding_cache rows whose last_used_at is older than
 * `input['cutoff_iso']`.
 *
 * NOTE: embedding_cache is INTENTIONALLY cross-tenant by design (see
 * {@see EmbeddingCache} class docblock — identical input text produces
 * identical vectors regardless of tenant, so cache reuse is a pure
 * cost win with no isolation risk). This step still calls
 * {@see StepTenantBinder} so flow_runs / flow_audit rows carry the
 * caller's tenant_id for forensic traceability, but the COUNT itself
 * is GLOBAL — no `forTenant()` filter on the query (the column does
 * not exist on the table).
 *
 * Read-only: dry-run safely runs the count.
 */
final class CountStaleEmbeddingsStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        $cutoff = $this->parseCutoff($context->input['cutoff_iso'] ?? null);

        // No `forTenant()` — embedding_cache is cross-tenant by design.
        $count = EmbeddingCache::query()
            ->where('last_used_at', '<', $cutoff)
            ->count();

        return FlowStepResult::success(
            output: [
                'cutoff_iso' => $cutoff->format(\DateTimeInterface::ATOM),
                'planned_count' => $count,
            ],
            businessImpact: ['planned_count' => $count],
        );
    }

    private function parseCutoff(mixed $raw): DateTimeImmutable
    {
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException(
                'CountStaleEmbeddingsStep: input["cutoff_iso"] must be a non-empty ISO 8601 string.'
            );
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'CountStaleEmbeddingsStep: input["cutoff_iso"] is not a valid ISO 8601 timestamp.',
                previous: $e,
            );
        }
    }
}
