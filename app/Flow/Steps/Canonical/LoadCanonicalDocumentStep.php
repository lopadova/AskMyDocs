<?php

declare(strict_types=1);

namespace App\Flow\Steps\Canonical;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeDocument;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 1 of {@see \App\Flow\Definitions\CanonicalIndexFlow}.
 *
 * Loads the {@see KnowledgeDocument} the indexer will operate on and
 * short-circuits with a typed `not_indexable` reason when the row is
 * missing, non-canonical, archived, or lacks a slug + canonical_type.
 *
 * Read-only: dry-run runs the load + validation so operators see whether
 * the doc is even eligible without making any DB writes downstream.
 *
 * R30 — runs under tenant context bound by {@see StepTenantBinder}; the
 * default soft-delete scope hides trashed rows, which is correct here
 * (an archived/soft-deleted doc must not contribute to the live graph).
 */
final class LoadCanonicalDocumentStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        $documentId = (int) ($context->input['document_id'] ?? 0);
        if ($documentId <= 0) {
            // R14 — fail loud on a malformed input, do NOT silently skip.
            throw new RuntimeException(
                'LoadCanonicalDocumentStep: input["document_id"] must be a positive integer.'
            );
        }

        $document = KnowledgeDocument::find($documentId);
        if ($document === null) {
            return $this->indexableShortCircuit('document_not_found', $documentId);
        }
        if (! $document->is_canonical) {
            return $this->indexableShortCircuit('not_canonical', $documentId);
        }
        if ($document->slug === null || $document->canonical_type === null) {
            return $this->indexableShortCircuit('missing_canonical_identifiers', $documentId);
        }
        if ($document->status === 'archived') {
            // Archived = a newer version of this doc has taken over; rebuilding
            // graph from the archived row would shadow the live version.
            return $this->indexableShortCircuit('archived', $documentId);
        }

        return FlowStepResult::success(
            output: [
                'indexable' => true,
                'document_id' => (int) $document->id,
                'project_key' => (string) $document->project_key,
                'slug' => (string) $document->slug,
                'doc_id' => $document->doc_id,
                'canonical_type' => (string) $document->canonical_type,
                'canonical_status' => $document->canonical_status,
                'retrieval_priority' => (int) $document->retrieval_priority,
                'title' => (string) $document->title,
            ],
            businessImpact: [
                'document_id' => (int) $document->id,
                'canonical_type' => (string) $document->canonical_type,
            ],
        );
    }

    /**
     * @return FlowStepResult
     */
    private function indexableShortCircuit(string $reason, int $documentId): FlowStepResult
    {
        return FlowStepResult::success(
            output: [
                'indexable' => false,
                'document_id' => $documentId,
                'reason' => $reason,
            ],
            businessImpact: ['indexable' => false, 'reason' => $reason],
        );
    }
}
