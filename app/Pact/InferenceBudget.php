<?php

declare(strict_types=1);

namespace App\Pact;

use App\Ai\AgentChatModelScope;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;
use Padosoft\PactAgent\Contracts\CoordinatorClient;
use Ramsey\Uuid\Uuid;

final class InferenceBudget
{
    public function reserve(): void
    {
        $context = app(AgentChatModelScope::class)->context();
        if ($context === null) return;
        $run = AgentRun::query()->forTenant($context->tenantId)->where('run_id', $context->runId)->first();
        if ($run !== null) app(SessionContinuation::class)->authorizeChild($run);
        $id = $run?->input_json['pact_task_id'] ?? null;
        if ($id === null) return;
        $row = DB::table('pact_agent_tasks')->where(['id' => $id, 'tenant_id' => $context->tenantId])->first();
        if (! $row) throw new \RuntimeException('Federation task is unavailable');
        $task = (array) $row;
        $task['payload'] = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        $parent = $task['payload']['input']['parent_task_id'] ?? null;
        if (! is_string($parent) || $parent === '') throw new \RuntimeException('Federation parent task is required');
        app(CoordinatorConnection::class)->resolve($task);
        DB::transaction(static function () use ($id, $parent, $task): void {
            DB::table('pact_inference_counters')->insertOrIgnore(['task_id' => $id, 'next_step' => 0]);
            $row = DB::table('pact_inference_counters')->where('task_id', $id)->lockForUpdate()->first();
            $index = (int) $row->next_step;
            $step = Uuid::uuid5(Uuid::NAMESPACE_URL, 'pact-step:'.$id.':'.$index)->toString();
            // If acknowledgement is lost, rollback retains the same index for a safe retry.
            app(CoordinatorClient::class)->reserveStep($parent, $step, $task);
            DB::table('pact_inference_counters')->where('task_id', $id)->update(['next_step' => $index + 1]);
        });
    }
}
