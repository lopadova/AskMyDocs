<?php

declare(strict_types=1);

namespace App\Flow\Steps\Prune;

use App\Flow\Steps\StepTenantBinder;
use App\Models\ChatLog;
use DateTimeImmutable;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 2 of {@see \App\Flow\Definitions\PruneChatLogsFlow}.
 *
 * Deletes chat_logs rows older than `input['cutoff_iso']` for the bound
 * tenant. R30 — delete is explicitly tenant-scoped via `forTenant()`.
 *
 * Dry-run skipped — DB write is the only artefact.
 */
final class DeleteStaleChatLogsStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $tenantId = (string) $context->input['tenant_id'];
        $cutoff = $this->parseCutoff($context->input['cutoff_iso'] ?? null);

        $deleted = ChatLog::query()
            ->forTenant($tenantId)
            ->where('created_at', '<', $cutoff)
            ->delete();

        return FlowStepResult::success(
            output: [
                'tenant_id' => $tenantId,
                'cutoff_iso' => $cutoff->format(\DateTimeInterface::ATOM),
                'deleted_count' => $deleted,
            ],
            businessImpact: ['deleted_count' => $deleted],
        );
    }

    private function parseCutoff(mixed $raw): DateTimeImmutable
    {
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException(
                'DeleteStaleChatLogsStep: input["cutoff_iso"] must be a non-empty ISO 8601 string.'
            );
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'DeleteStaleChatLogsStep: input["cutoff_iso"] is not a valid ISO 8601 timestamp.',
                previous: $e,
            );
        }
    }
}
