<?php

declare(strict_types=1);

namespace App\Flow\Definitions;

use App\Flow\Compensators\DeleteCanonicalMarkdownCompensator;
use App\Flow\Steps\Promotion\DispatchPromotionIngestStep;
use App\Flow\Steps\Promotion\ValidateFrontmatterStep;
use App\Flow\Steps\Promotion\WriteCanonicalMarkdownStep;
use Padosoft\LaravelFlow\FlowEngine;

/**
 * `kb.promote` — 4-step human-gated promotion saga (ADR 0003 compliant).
 *
 * Refactor of {@see \App\Services\Kb\Canonical\CanonicalWriter::write()}
 * + {@see \App\Jobs\IngestDocumentJob::dispatchForCurrentTenant()} into
 * a Flow definition with an explicit operator approval gate before any
 * mutation hits disk.
 *
 * Steps:
 *   1. validate-frontmatter           (dry-run-safe)
 *      Runs CanonicalParser::parse + validate. Throws on invalid input
 *      so the engine fails the run BEFORE issuing an approval token.
 *   2. approval-gate                  (built-in, dry-run-safe)
 *      Pauses the run until an operator calls Flow::resume($token) or
 *      Flow::reject($token) via {@see \Padosoft\LaravelFlow\Console\ApproveFlowCommand}
 *      or the future flow-admin SPA. Token TTL from
 *      `config('laravel-flow.approval.token_ttl_minutes')`.
 *   3. write-markdown                 (mutates disk)
 *      Calls CanonicalWriter::write(). Records a `promoted` event in
 *      kb_canonical_audit with the operator-supplied actor when present.
 *      ▶ Compensator: DeleteCanonicalMarkdownCompensator removes the
 *        file from disk if dispatch-ingest fails.
 *   4. dispatch-ingest                (best-effort, idempotent)
 *      Dispatches IngestDocumentJob so the kb.ingest saga builds the
 *      typed projection. The job is idempotent on
 *      `(tenant_id, project_key, source_path)` so retries are safe.
 *
 * Approval rejection: when the operator calls Flow::reject(token) the
 * engine never executes write-markdown — the disk stays untouched and
 * no compensation is needed. The reject path is observable via
 * `flow_runs.status = 'rejected'` and the audit listener (registered in
 * FlowServiceProvider) writes a `rejected_promotion` row to
 * kb_canonical_audit so the editorial trail records the refusal.
 */
final class PromotionFlow
{
    public const NAME = 'kb.promote';

    public const APPROVAL_STEP = 'approval-gate';

    public static function register(FlowEngine $engine): void
    {
        $engine->define(self::NAME)
            ->withInput([
                // R30/R31 — tenant_id rides the input bag.
                'tenant_id',
                'project_key',
                'markdown',
                // Optional inputs (no validation — handlers default safely):
                //   - title: human-readable title for the ingest dispatch
                //   - promotion_source: 'api' | 'cli' | 'flow-admin'
            ])
            ->step('validate-frontmatter', ValidateFrontmatterStep::class)
                ->withDryRun(true)
            ->approvalGate(self::APPROVAL_STEP)
            ->step('write-markdown', WriteCanonicalMarkdownStep::class)
                ->compensateWith(DeleteCanonicalMarkdownCompensator::class)
            ->step('dispatch-ingest', DispatchPromotionIngestStep::class)
            ->register();
    }
}
