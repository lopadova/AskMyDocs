<?php

declare(strict_types=1);

namespace App\Flow\Compensators;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KbNode;
use Padosoft\LaravelFlow\FlowCompensator;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Compensator for the `populate-nodes` step of {@see \App\Flow\Definitions\CanonicalIndexFlow}.
 *
 * Triggered when a step AFTER `populate-nodes` (currently only
 * `populate-edges`) fails. Removes ONLY the {@see KbNode} rows the
 * `populate-nodes` step inserted in this run — pre-existing nodes are
 * left intact, so a transient indexing failure on doc A doesn't wipe
 * out doc B's graph contributions if A was the only "creator" of a
 * shared dangling target.
 *
 * The composite FK on `kb_edges.(project_key, from/to_node_uid)` →
 * `kb_nodes.(project_key, node_uid)` cascades on node deletion, so any
 * edges inserted by the prior `populate-edges` attempt cascade away with
 * their endpoint nodes — no separate edge cleanup is required.
 *
 * Per R4 + R14 — never silently swallow compensation failures. Letting
 * exceptions propagate lets the Flow engine mark
 * `flow_runs.compensation_status = failed` so operators see it.
 *
 * Idempotent: a second invocation finds nothing to delete and returns
 * cleanly.
 */
final class RollbackCanonicalNodesCompensator implements FlowCompensator
{
    public function compensate(FlowContext $context, FlowStepResult $stepResult): void
    {
        StepTenantBinder::bindFromContext($context);

        $output = $stepResult->output;
        /** @var list<int> $createdNodeIds */
        $createdNodeIds = is_array($output['created_node_ids'] ?? null)
            ? array_values(array_filter(
                $output['created_node_ids'],
                static fn ($v): bool => is_int($v) || (is_string($v) && ctype_digit($v)),
            ))
            : [];
        $createdNodeIds = array_map('intval', $createdNodeIds);

        if ($createdNodeIds === []) {
            return;
        }

        // R30/R31 — scope the delete to the active tenant. A tainted /
        // serialized `created_node_ids` payload could otherwise contain
        // node ids that belong to ANOTHER tenant; without `forTenant()`
        // the bare `whereIn('id', ...)` would happily delete them.
        // Iteration 4 (PR #116) — Copilot flagged this hole.
        $tenantId = (string) ($context->input['tenant_id'] ?? '');

        // R3 — chunk the IN list to keep individual queries bounded even
        // when a single doc creates a huge dangling-target set.
        foreach (array_chunk($createdNodeIds, 1000) as $chunk) {
            KbNode::query()
                ->forTenant($tenantId)
                ->whereIn('id', $chunk)
                ->delete();
        }
    }
}
