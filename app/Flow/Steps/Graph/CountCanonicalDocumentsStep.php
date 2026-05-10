<?php

declare(strict_types=1);

namespace App\Flow\Steps\Graph;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeDocument;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Step 1 of {@see \App\Flow\Definitions\RebuildGraphFlow}.
 *
 * Counts canonical documents in the bound tenant (and optionally a
 * single project_key when `input['project_key']` is non-empty). R30 —
 * read is explicitly tenant-scoped via `forTenant()`.
 *
 * Read-only: dry-run safely runs the count.
 */
final class CountCanonicalDocumentsStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        $tenantId = (string) $context->input['tenant_id'];
        $projectKey = (string) ($context->input['project_key'] ?? '');

        $query = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('is_canonical', true);
        if ($projectKey !== '') {
            $query->where('project_key', $projectKey);
        }

        $count = $query->count();

        return FlowStepResult::success(
            output: [
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'canonical_count' => $count,
            ],
            businessImpact: ['canonical_count' => $count],
        );
    }
}
