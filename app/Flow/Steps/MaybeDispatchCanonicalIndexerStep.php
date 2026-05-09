<?php

declare(strict_types=1);

namespace App\Flow\Steps;

use App\Jobs\CanonicalIndexerJob;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 5 of {@see \App\Flow\Definitions\IngestDocumentFlow}.
 *
 * If the persisted document is canonical, dispatches
 * {@see CanonicalIndexerJob} to (re-)build kb_nodes / kb_edges. Otherwise
 * a no-op. The job has its own retry semantics; this step always
 * succeeds when it returns (the dispatch itself is non-blocking on a
 * sync queue and async otherwise).
 *
 * Failure of this step (e.g. a queue connection outage on a sync queue
 * surfaces as an exception from `dispatch()`) triggers compensation of
 * the previous step (`persist-chunks`) — the {@see RollbackChunksCompensator}
 * force-deletes the document so the saga unwinds cleanly.
 *
 * NOT dry-run-safe — dispatch is a side effect.
 */
final class MaybeDispatchCanonicalIndexerStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $persistOutput = $context->stepOutputs['persist-chunks'] ?? null;
        if (! is_array($persistOutput)) {
            throw new RuntimeException(
                'MaybeDispatchCanonicalIndexerStep: missing prior step output [persist-chunks].'
            );
        }

        $documentId = (int) ($persistOutput['knowledge_document_id'] ?? 0);
        $isCanonical = (bool) ($persistOutput['is_canonical'] ?? false);

        if ($documentId <= 0) {
            // Persist step succeeded but produced no doc id — defensive
            // failure (would point at a bug in PersistChunksStep).
            throw new RuntimeException(
                'MaybeDispatchCanonicalIndexerStep: invalid knowledge_document_id from persist-chunks.'
            );
        }

        if (! $isCanonical) {
            return FlowStepResult::success(
                output: [
                    'dispatched' => false,
                    'reason' => 'document is not canonical',
                ],
                businessImpact: ['indexer_dispatched' => false],
            );
        }

        CanonicalIndexerJob::dispatch($documentId);

        return FlowStepResult::success(
            output: [
                'dispatched' => true,
                'document_id' => $documentId,
            ],
            businessImpact: ['indexer_dispatched' => true],
        );
    }
}
