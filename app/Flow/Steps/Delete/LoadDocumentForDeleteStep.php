<?php

declare(strict_types=1);

namespace App\Flow\Steps\Delete;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeDocument;
use App\Support\KbPath;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 1 of {@see \App\Flow\Definitions\DeleteDocumentFlow}.
 *
 * Loads the {@see KnowledgeDocument} the delete saga will operate on.
 * Accepts either `document_id` (already-resolved row) or
 * `(project_key, source_path)` (lookup by path) so the same flow serves
 * both the {@see \App\Console\Commands\KbDeleteCommand} (path-based) and
 * the {@see \App\Http\Controllers\Api\KbDeleteController} (path-based)
 * call sites uniformly.
 *
 * R2 — uses `withTrashed()` because force-delete must reach an
 * already-soft-deleted row to promote it to a hard delete.
 *
 * Read-only: dry-run resolves the row so operators see "would delete
 * this row" before committing.
 */
final class LoadDocumentForDeleteStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        $documentId = isset($context->input['document_id'])
            ? (int) $context->input['document_id']
            : 0;
        $projectKey = (string) ($context->input['project_key'] ?? '');
        $sourcePath = (string) ($context->input['source_path'] ?? '');

        $tenantId = (string) $context->input['tenant_id'];
        $document = $this->resolveDocument($tenantId, $documentId, $projectKey, $sourcePath);
        if ($document === null) {
            // Surface as a typed not-found state rather than throwing;
            // the controller / CLI translate this into a 404 / failure
            // exit code (preserving existing UX).
            return FlowStepResult::success(
                output: [
                    'found' => false,
                    'document_id' => $documentId,
                    'project_key' => $projectKey,
                    'source_path' => $sourcePath,
                ],
                businessImpact: ['found' => false],
            );
        }

        return FlowStepResult::success(
            output: [
                'found' => true,
                'document_id' => (int) $document->id,
                'project_key' => (string) $document->project_key,
                'source_path' => (string) $document->source_path,
                'already_trashed' => (bool) $document->trashed(),
                'is_canonical' => (bool) $document->is_canonical,
                'doc_id' => $document->doc_id,
                'slug' => $document->slug,
            ],
            businessImpact: [
                'document_id' => (int) $document->id,
                'already_trashed' => (bool) $document->trashed(),
            ],
        );
    }

    private function resolveDocument(string $tenantId, int $documentId, string $projectKey, string $sourcePath): ?KnowledgeDocument
    {
        if ($documentId > 0) {
            // R30 — explicit tenant scope on the by-id lookup. Without it,
            // a numeric id collision would silently resolve another
            // tenant's row. Soft-deleted rows are still visible because the
            // delete saga must reach already-soft-deleted rows for a
            // force-delete promotion.
            return KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->withTrashed()
                ->find($documentId);
        }
        if ($projectKey === '' || $sourcePath === '') {
            throw new RuntimeException(
                'LoadDocumentForDeleteStep: input must include either document_id (>0) or both project_key and source_path.'
            );
        }
        $normalized = KbPath::normalize($sourcePath);
        // R30 — explicit tenant scope on the path lookup. Two tenants can
        // legitimately share the same project_key + source_path; without
        // forTenant() this would resolve the wrong tenant's row when one
        // tenant deletes a path that another tenant also has.
        return KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->withTrashed()
            ->where('project_key', $projectKey)
            ->where('source_path', $normalized)
            ->first();
    }
}
