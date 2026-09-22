<?php

declare(strict_types=1);

namespace App\Flow\Compensators;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeDocument;
use App\Scopes\AccessScopeScope;
use App\Services\Kb\DocumentDeleter;
use App\Support\TenantContext;
use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Compensator for the `persist-chunks` step of the {@see \App\Flow\Definitions\IngestDocumentFlow}.
 *
 * Triggered when any step AFTER `persist-chunks` fails (currently only
 * `maybe-dispatch-canonical-indexer`). Removes the KnowledgeDocument row
 * + dependents from the database so the saga unwinds cleanly:
 *
 *   - `knowledge_chunks` rows cascade via FK ON DELETE CASCADE.
 *   - `kb_nodes` / `kb_edges` cascade via {@see DocumentDeleter::cascadeGraphFor()}.
 *   - The physical KB file on disk is PRESERVED.
 *
 * Per Copilot PR #115 review iteration 1: the original implementation
 * called `DocumentDeleter::delete($doc, force: true)`, which also wiped
 * the file on disk via {@see DocumentDeleter::removeFile()}. For
 * `kb:ingest-folder` flows that scan a Git mirror or a network share,
 * a transient failure in the canonical indexer dispatch would
 * PERMANENTLY DESTROY the source-of-truth markdown file the operator
 * never asked to delete. {@see DocumentDeleter::deleteDbOnly()}
 * (introduced for this fix) explicitly skips the file removal so the
 * next ingest retry can re-process the same untouched bytes.
 *
 * Idempotent: if the document was already deleted (race with another
 * compensator invocation, or already cleaned up by a sibling rollback),
 * the second pass is a no-op.
 */
final class RollbackChunksCompensator implements FlowCompensator
{
    public function __construct(
        private readonly DocumentDeleter $deleter,
    ) {}

    public function compensate(FlowContext $context, FlowStepResult $stepResult): void
    {
        StepTenantBinder::bindFromContext($context);

        $output = $stepResult->output;
        $documentId = (int) ($output['knowledge_document_id'] ?? 0);
        if ($documentId <= 0) {
            return;
        }

        // R2 — soft-deleted rows must remain reachable here so the
        // compensator can promote a soft delete to a hard delete on the
        // (rare) re-entry path.
        // R30 — and scoped to the tenant the context just bound: a stale or
        // replayed flow output naming another tenant's id must find nothing,
        // never delete that tenant's row (and, since v8.36, its artifact).
        //
        // AccessScopeScope is deliberately LIFTED here too, on the PRIMARY
        // lookup: it narrows what the CURRENT authenticated actor may READ,
        // which has nothing to do with whether this tenant-owned row needs
        // to be unwound. Compensation is a system-level cleanup, not a
        // user-facing read — a row hidden by a request-scoped project/path
        // ACL is exactly as much this saga's to roll back as a visible one.
        // Applying the scope here (as an earlier revision did) would leave
        // the document, its chunks, its graph projection and its conversion
        // artifact behind while reporting nothing at all (R14) — the tenant
        // filter above is the boundary that actually matters for this
        // operation, not the current reader's project membership.
        $document = KnowledgeDocument::withoutGlobalScope(AccessScopeScope::class)
            ->withTrashed()
            ->forTenant(app(TenantContext::class)->current())
            ->find($documentId);
        if ($document === null) {
            // Already gone — saga rollback is idempotent by contract.
            return;
        }

        // Per Copilot PR #115 review iteration 1 — never silently swallow
        // compensation failures (R4 + R14). Letting the exception
        // propagate lets the Flow engine mark compensation as failed,
        // which surfaces in flow_runs.compensation_status for operators
        // and dashboard alerting.
        $this->deleter->deleteDbOnly($document);
    }
}
