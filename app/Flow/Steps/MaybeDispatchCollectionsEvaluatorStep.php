<?php

declare(strict_types=1);

namespace App\Flow\Steps;

use App\Jobs\EvaluateCollectionsJob;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

final class MaybeDispatchCollectionsEvaluatorStep implements FlowStepHandler
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
                'MaybeDispatchCollectionsEvaluatorStep: missing prior step output [persist-chunks].'
            );
        }

        $documentId = (int) ($persistOutput['knowledge_document_id'] ?? 0);
        if ($documentId <= 0) {
            throw new RuntimeException(
                'MaybeDispatchCollectionsEvaluatorStep: invalid knowledge_document_id from persist-chunks.'
            );
        }

        $tenantId = (string) ($context->input['tenant_id'] ?? 'default');
        EvaluateCollectionsJob::dispatch($documentId, $tenantId);

        return FlowStepResult::success(
            output: [
                'dispatched' => true,
                'knowledge_document_id' => $documentId,
            ],
            businessImpact: ['collections_evaluator_dispatched' => true],
        );
    }
}

