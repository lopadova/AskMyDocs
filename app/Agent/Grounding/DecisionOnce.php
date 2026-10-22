<?php

declare(strict_types=1);

namespace App\Agent\Grounding;

use App\Decisions\DecisionResult;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;

/** Each bounded phase has a durable slot; retrying that phase never redispatches it. */
final class DecisionOnce
{
    public function run(?string $runId, string $signature, \Closure $call, string $slot = 'focused_decision'): DecisionResult
    {
        if ($runId === null) {
            return $call();
        }
        $memo = DB::transaction(function () use ($runId, $signature, $slot): array {
            $run = AgentRun::query()->where('run_id', $runId)->lockForUpdate()->first();
            if ($run === null) {
                return ['ephemeral' => true]; // Unit-level/non-durable synthesizer use.
            }
            $payload = $run->result_json ?? [];
            if (isset($payload[$slot])) {
                return $payload[$slot];
            }
            $payload[$slot] = ['signature' => $signature, 'status' => 'dispatched'];
            $run->forceFill(['result_json' => $payload])->save();
            return ['new' => true];
        });
        if (isset($memo['answers']) && ($memo['signature'] ?? '') === $signature) {
            return new DecisionResult($memo['id'], $memo['model'], $memo['answers'], $memo['usage'], $memo['latency_ms'], $memo['answer_errors'] ?? []);
        }
        if (! isset($memo['new']) && ! isset($memo['ephemeral'])) {
            throw new \RuntimeException('Semantic decision already dispatched; no automatic replay.');
        }
        $result = $call();
        if (! isset($memo['ephemeral'])) {
            DB::transaction(function () use ($runId, $signature, $result, $slot): void {
                $run = AgentRun::query()->where('run_id', $runId)->lockForUpdate()->firstOrFail();
                $run->forceFill(['result_json' => [...($run->result_json ?? []), $slot => [
                    'signature' => $signature, 'status' => 'completed', 'id' => $result->id, 'model' => $result->model,
                    'answers' => $result->answers, 'usage' => $result->usage, 'latency_ms' => $result->latencyMs,
                    ...($result->answerErrors === [] ? [] : ['answer_errors' => $result->answerErrors]),
                ]]])->save();
            });
        }
        return $result;
    }
}
