<?php

declare(strict_types=1);

namespace App\Flow\Steps\Prune;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use DateTimeImmutable;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 2 of {@see \App\Flow\Definitions\PruneDeletedFlow}.
 *
 * Hard-deletes the soft-deleted documents counted by step 1. Walks the
 * tenant-scoped onlyTrashed() set with chunkById(100) (R3) and routes
 * each row through {@see DocumentDeleter::delete()} with `force=true`
 * so chunks cascade, kb_node/kb_edge cascade, and the deprecation
 * audit row is written.
 *
 * Dry-run skipped — DB + disk mutation is the only artefact.
 */
final class HardDeleteSoftDeletedStep implements FlowStepHandler
{
    public function __construct(
        private readonly DocumentDeleter $deleter,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $tenantId = (string) $context->input['tenant_id'];
        $cutoff = $this->parseCutoff($context->input['cutoff_iso'] ?? null);

        $deleted = 0;
        // R3 — chunkById uses `id > ?` cursoring so it stays correct even
        // though forceDelete() removes each row as we iterate.
        // R30 — explicit tenant scope on the read.
        KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$deleted): void {
                foreach ($rows as $row) {
                    $this->deleter->delete($row, force: true);
                    $deleted++;
                }
            });

        return FlowStepResult::success(
            output: [
                'tenant_id' => $tenantId,
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
                'HardDeleteSoftDeletedStep: input["cutoff_iso"] must be a non-empty ISO 8601 string.'
            );
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'HardDeleteSoftDeletedStep: input["cutoff_iso"] is not a valid ISO 8601 timestamp.',
                previous: $e,
            );
        }
    }
}
