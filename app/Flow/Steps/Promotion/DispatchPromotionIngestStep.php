<?php

declare(strict_types=1);

namespace App\Flow\Steps\Promotion;

use App\Flow\Steps\StepTenantBinder;
use App\Jobs\IngestDocumentJob;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 4 of {@see \App\Flow\Definitions\PromotionFlow}.
 *
 * Dispatches {@see IngestDocumentJob} for the freshly-written canonical
 * markdown so the full kb.ingest saga (parse → chunk → embed → persist
 * → maybe-dispatch-canonical-indexer) builds the typed projection in
 * `knowledge_documents` + `knowledge_chunks` + the graph tables.
 *
 * Idempotent: the inner DocumentIngestor::findExistingVersion()
 * short-circuits on identical content, and the engine-level idempotency
 * key on kb.ingest dedups by `(tenant_id, project_key, source_path)`.
 *
 * If this step throws (e.g. queue connection refused on a sync queue),
 * the previous WriteCanonicalMarkdownStep compensator
 * ({@see \App\Flow\Compensators\DeleteCanonicalMarkdownCompensator})
 * removes the file from disk so the operator can retry without leaving
 * a half-promoted file behind.
 */
final class DispatchPromotionIngestStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $writeOutput = $context->stepOutputs['write-markdown'] ?? null;
        if (! is_array($writeOutput)) {
            throw new RuntimeException(
                'DispatchPromotionIngestStep: missing prior step output [write-markdown].'
            );
        }

        $projectKey = (string) ($writeOutput['project_key'] ?? '');
        $relativePath = (string) ($writeOutput['relative_path'] ?? '');
        $disk = (string) ($writeOutput['disk'] ?? config('kb.sources.disk', 'kb'));
        if ($projectKey === '' || $relativePath === '') {
            throw new RuntimeException(
                'DispatchPromotionIngestStep: invalid project_key/relative_path from write-markdown step.'
            );
        }

        $title = (string) ($context->input['title'] ?? '');
        if ($title === '') {
            $title = $writeOutput['slug'] !== null
                ? (string) $writeOutput['slug']
                : pathinfo($relativePath, PATHINFO_FILENAME);
        }

        // PR #115 review iteration 1 — capture TenantContext at dispatch
        // time. We use the tenant_id from the FLOW input bag (not from
        // the current request) because the flow may be resumed in a
        // different request from the one that issued the approval.
        $tenantId = (string) ($context->input['tenant_id'] ?? 'default');

        IngestDocumentJob::dispatch(
            projectKey: $projectKey,
            relativePath: $relativePath,
            disk: $disk,
            title: $title,
            metadata: [
                'disk' => $disk,
                'prefix' => (string) config('kb.sources.path_prefix', ''),
                'promotion_source' => (string) ($context->input['promotion_source'] ?? 'flow'),
                'promotion_flow_run_id' => $context->flowRunId,
            ],
            mimeType: null,
            tenantId: $tenantId,
        );

        return FlowStepResult::success(
            output: [
                'dispatched' => true,
                'project_key' => $projectKey,
                'relative_path' => $relativePath,
            ],
            businessImpact: ['ingest_dispatched' => true],
        );
    }
}
