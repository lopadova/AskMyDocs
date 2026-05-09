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
 * Step 1 of {@see \App\Flow\Definitions\PruneChatLogsFlow}.
 *
 * Counts chat_logs rows older than `input['cutoff_iso']` for the
 * tenant bound by {@see StepTenantBinder}. R30 — read is explicitly
 * tenant-scoped via `forTenant($tenantId)`.
 *
 * Read-only: dry-run safely runs the count.
 */
final class CountStaleChatLogsStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        $tenantId = (string) $context->input['tenant_id'];
        $cutoff = $this->parseCutoff($context->input['cutoff_iso'] ?? null);

        $count = ChatLog::query()
            ->forTenant($tenantId)
            ->where('created_at', '<', $cutoff)
            ->count();

        return FlowStepResult::success(
            output: [
                'tenant_id' => $tenantId,
                'cutoff_iso' => $cutoff->format(\DateTimeInterface::ATOM),
                'planned_count' => $count,
            ],
            businessImpact: ['planned_count' => $count],
        );
    }

    private function parseCutoff(mixed $raw): DateTimeImmutable
    {
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException(
                'CountStaleChatLogsStep: input["cutoff_iso"] must be a non-empty ISO 8601 string.'
            );
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'CountStaleChatLogsStep: input["cutoff_iso"] is not a valid ISO 8601 timestamp.',
                previous: $e,
            );
        }
    }
}
