<?php

declare(strict_types=1);

namespace App\Flow\Steps\Graph;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KbEdge;
use App\Models\KbNode;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Step 2 of {@see \App\Flow\Definitions\RebuildGraphFlow}.
 *
 * Truncates kb_edges + kb_nodes for the bound tenant (and optionally a
 * single project_key). Skipped when `input['truncate'] === false`
 * (operator opted into an additive rebuild via `--no-truncate`).
 *
 * Edges cascade on node delete via the composite FK on
 * (tenant_id, project_key, from/to_node_uid). We delete edges
 * explicitly first so the intent is clear in the DB log AND so the
 * cascade order is deterministic across drivers (some drivers defer
 * cascades; explicit DELETE is unambiguous).
 *
 * NOT REVERSIBLE through a compensator: a compensator would need a
 * pre-truncate snapshot of the entire graph, which on large tenants
 * would defeat the purpose of "rebuild from canonical sources". The
 * source-of-truth is the canonical markdown on disk; if step 3
 * (DispatchCanonicalIndexerFanOutStep) fails after this step, the
 * operator simply re-runs `kb:rebuild-graph`. Document this trade-off
 * loudly in the Flow definition's class docblock.
 *
 * R30 — every delete is explicitly tenant-scoped.
 *
 * Dry-run skipped — DB write is the only artefact.
 */
final class TruncateGraphScopeStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $truncate = (bool) ($context->input['truncate'] ?? true);
        if (! $truncate) {
            return FlowStepResult::success(
                output: ['truncated' => false, 'reason' => 'no_truncate_opt'],
                businessImpact: ['truncated' => false],
            );
        }

        $tenantId = (string) $context->input['tenant_id'];
        $projectKey = (string) ($context->input['project_key'] ?? '');

        [$edgesDeleted, $nodesDeleted] = DB::transaction(function () use ($tenantId, $projectKey): array {
            $edgesQuery = KbEdge::query()->where('tenant_id', $tenantId);
            $nodesQuery = KbNode::query()->where('tenant_id', $tenantId);
            if ($projectKey !== '') {
                $edgesQuery->where('project_key', $projectKey);
                $nodesQuery->where('project_key', $projectKey);
            }
            $edges = (int) $edgesQuery->delete();
            $nodes = (int) $nodesQuery->delete();
            return [$edges, $nodes];
        });

        return FlowStepResult::success(
            output: [
                'truncated' => true,
                'edges_deleted' => $edgesDeleted,
                'nodes_deleted' => $nodesDeleted,
            ],
            businessImpact: [
                'truncated' => true,
                'edges_deleted' => $edgesDeleted,
                'nodes_deleted' => $nodesDeleted,
            ],
        );
    }
}
