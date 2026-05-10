<?php

declare(strict_types=1);

namespace App\Flow\Definitions;

use App\Flow\Compensators\RollbackCanonicalNodesCompensator;
use App\Flow\Steps\Canonical\LoadCanonicalDocumentStep;
use App\Flow\Steps\Canonical\PopulateCanonicalEdgesStep;
use App\Flow\Steps\Canonical\PopulateCanonicalNodesStep;
use Padosoft\LaravelFlow\FlowEngine;

/**
 * `kb.canonical-index` — 3-step refactor of the legacy
 * {@see \App\Jobs\CanonicalIndexerJob} handler. Each phase becomes
 * independently observable (flow_steps + flow_audit rows) and the
 * node-population step gets a compensator that rolls back exactly the
 * KbNode rows it inserted if a downstream step fails.
 *
 * Steps:
 *   1. load-document             (dry-run-safe)
 *      Loads the KnowledgeDocument by id; emits an `indexable=false` short-
 *      circuit when the row is missing, non-canonical, archived, or lacks
 *      slug + canonical_type. The downstream steps honour that flag.
 *   2. populate-nodes            (mutates DB)
 *      Upserts the self-node + every wikilink / frontmatter target as
 *      dangling. Records `created_node_ids` so the compensator only
 *      removes nodes THIS run inserted.
 *      ▶ Compensator: RollbackCanonicalNodesCompensator deletes those.
 *   3. populate-edges            (mutates DB)
 *      Wipes the prior outgoing edge set, inserts the fresh edges from
 *      frontmatter + chunk wikilinks, writes the `graph_rebuild` audit row.
 *      No dedicated compensator: if a step were ever appended after this
 *      and failed, the prior `populate-nodes` compensator would
 *      cascade-delete these edges via the composite FK.
 *
 * Tenant context: every step starts with {@see \App\Flow\Steps\StepTenantBinder}
 * which fails-loud on a missing/empty `tenant_id`. {@see \App\Jobs\CanonicalIndexerJob}
 * captures the active tenant at dispatch time and forwards it via the
 * `input['tenant_id']` bag — see PR #115 R30 lessons.
 */
final class CanonicalIndexFlow
{
    public const NAME = 'kb.canonical-index';

    public static function register(FlowEngine $engine): void
    {
        $engine->define(self::NAME)
            ->withInput([
                // R30/R31 — tenant_id flows through the input bag because
                // FlowContext does not expose FlowExecutionOptions.
                'tenant_id',
                'document_id',
            ])
            ->step('load-document', LoadCanonicalDocumentStep::class)
                ->withDryRun(true)
            ->step('populate-nodes', PopulateCanonicalNodesStep::class)
                ->compensateWith(RollbackCanonicalNodesCompensator::class)
            ->step('populate-edges', PopulateCanonicalEdgesStep::class)
            ->register();
    }
}
