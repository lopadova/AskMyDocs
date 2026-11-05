<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;

/** Close unobserved work under the same run lock as its terminal event. */
final class AgentTerminalExecutionFinalizer
{
    /** @return list<array{tool: string, execution_id: int, action_id: mixed, wave_id: mixed, error_code: string}> */
    public function close(AgentRun $run): array
    {
        return DB::transaction(function () use ($run): array {
            $locked = AgentRun::query()->forTenant($run->tenant_id)->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isTerminal()) return [];

            $reason = $locked->status === AgentRun::STATUS_CANCELLED ? 'run_cancelled' : 'run_interrupted';
            if ($locked->status === AgentRun::STATUS_FAILED && is_string($locked->error_code) && $locked->error_code !== '') {
                $reason = $locked->error_code;
            }
            $endedAt = $locked->completed_at ?? now();
            $outcomes = [];
            foreach ($locked->toolExecutions()->whereIn('status', ['queued', 'running', 'pending'])->orderBy('logical_index')->get() as $execution) {
                $meta = (array) $execution->result_meta_json;
                $execution->forceFill([
                    'status' => $locked->status === AgentRun::STATUS_CANCELLED ? 'cancelled' : 'failed',
                    'error_code' => $reason,
                    'completed_at' => $endedAt,
                    'latency_ms' => $execution->latency_ms ?? ($execution->started_at === null ? null
                        : (int) min(2147483647, max(0, $execution->started_at->diffInMilliseconds($endedAt)))),
                    'result_meta_json' => [...$meta, 'complete' => false, 'stop_reason' => $reason,
                        'terminal_cleanup' => true, 'outcome_unknown' => true],
                ])->save();
                $outcomes[] = ['tool' => $execution->tool_name, 'execution_id' => $execution->id,
                    'action_id' => $meta['action_id'] ?? null, 'wave_id' => $meta['wave_id'] ?? null, 'error_code' => $reason];
            }

            $state = (array) $locked->result_json;
            $changed = $outcomes !== [];
            foreach ((array) ($state['waves'] ?? []) as $id => $wave) {
                if (! is_array($wave) || ! in_array($wave['status'] ?? null, ['running', 'waiting'], true)) continue;
                $state['waves'][$id] = [...$wave, 'status' => $locked->status === AgentRun::STATUS_CANCELLED ? 'cancelled' : 'failed',
                    'error_code' => $reason];
                $changed = true;
            }
            if (($state['wave_dispatching'] ?? false) === true) {
                $state['wave_dispatching'] = false;
                $changed = true;
            }
            if ($changed) {
                $state['phase'] = 'final';
                $locked->forceFill(['result_json' => $state])->save();
                $run->setAttribute('result_json', $state)->syncOriginalAttribute('result_json');
            }

            return $outcomes;
        });
    }
}
