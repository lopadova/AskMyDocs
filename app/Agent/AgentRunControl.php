<?php

declare(strict_types=1);

namespace App\Agent;

use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;

final readonly class AgentRunControl
{
    public function __construct(private AgentEventPublisher $events) {}

    public function cancel(AgentRun $run): AgentRun
    {
        $updated = DB::transaction(function () use ($run): AgentRun {
            /** @var AgentRun $locked */
            $locked = AgentRun::query()
                ->forTenant($run->tenant_id)
                ->whereKey($run->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->isTerminal()) {
                throw new \DomainException('run_not_cancellable');
            }

            $locked->forceFill([
                'status' => AgentRun::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'completed_at' => now(),
            ])->save();

            return $locked;
        });

        $this->events->publish($updated, 'run.cancelled', 'run.cancelled', canCancel: false);

        return $updated->refresh();
    }

    /** @param array<string,int> $required */
    public function awaitConfirmation(AgentRun $run, array $required): AgentRun
    {
        $run->forceFill([
            'status' => AgentRun::STATUS_AWAITING_CONFIRMATION,
            'budget_json' => array_merge($run->budget_json ?? [], ['requested_extension' => $required]),
        ])->save();

        $this->events->publish(
            $run,
            'run.awaiting_confirmation',
            'run.awaiting_confirmation',
            data: ['requested_extension' => $required],
            canCancel: true,
        );

        return $run->refresh();
    }

    /** @param array<string,mixed> $interaction */
    public function awaitMcpInteraction(AgentRun $run, string $reason, array $interaction): AgentRun
    {
        $status = match ($reason) {
            'mcp_confirmation_required' => AgentRun::STATUS_AWAITING_MCP_CONFIRMATION,
            'mcp_input_required' => AgentRun::STATUS_AWAITING_MCP_INPUT,
            'mcp_task_accepted' => AgentRun::STATUS_WAITING_MCP_TASK,
            default => throw new \InvalidArgumentException("Unsupported MCP interaction [{$reason}]."),
        };
        $checkpoint = is_array($run->result_json) ? $run->result_json : [];
        $run->forceFill([
            'status' => $status,
            'result_json' => array_merge($checkpoint, [
                'pending_mcp_interaction' => $interaction + ['reason' => $reason],
            ]),
        ])->save();

        $this->events->publish(
            $run,
            'run.mcp_interaction_required',
            null,
            data: ['status' => $status, 'reason' => $reason, 'interaction' => $interaction],
            canCancel: true,
        );

        return $run->refresh();
    }

    public function resume(AgentRun $run, int $logicalExtension, int $physicalExtension): AgentRun
    {
        $logicalMax = max(1, (int) config('agent.limits.confirmation_logical_extension_max', 25));
        $physicalMax = max(1, (int) config('agent.limits.confirmation_physical_extension_max', 100));
        if ($logicalExtension < 0 || $logicalExtension > $logicalMax
            || $physicalExtension < 1 || $physicalExtension > $physicalMax) {
            throw new \DomainException('extension_out_of_bounds');
        }

        $updated = DB::transaction(function () use ($run, $logicalExtension, $physicalExtension): AgentRun {
            /** @var AgentRun $locked */
            $locked = AgentRun::query()
                ->forTenant($run->tenant_id)
                ->whereKey($run->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status !== AgentRun::STATUS_AWAITING_CONFIRMATION) {
                throw new \DomainException('run_not_awaiting_confirmation');
            }

            $budget = $locked->budget_json ?? [];
            $budget['confirmed_logical_extension'] = (int) ($budget['confirmed_logical_extension'] ?? 0) + $logicalExtension;
            $budget['confirmed_physical_extension'] = (int) ($budget['confirmed_physical_extension'] ?? 0) + $physicalExtension;
            unset($budget['requested_extension']);

            $locked->forceFill([
                'status' => AgentRun::STATUS_QUEUED,
                'budget_json' => $budget,
                'error_code' => null,
            ])->save();

            return $locked;
        });

        $this->events->publish(
            $updated,
            'budget.extended',
            'budget.extended',
            data: [
                'logical_extension' => $logicalExtension,
                'physical_extension' => $physicalExtension,
            ],
        );
        ExecuteAgentRunJob::dispatch($updated->id, $updated->tenant_id);

        return $updated->refresh();
    }

    public function ensureActive(AgentRun $run): void
    {
        $status = AgentRun::query()
            ->forTenant($run->tenant_id)
            ->whereKey($run->id)
            ->value('status');
        if ($status === AgentRun::STATUS_CANCELLED) {
            throw new AgentRunCancelledException($run->run_id);
        }
    }
}
