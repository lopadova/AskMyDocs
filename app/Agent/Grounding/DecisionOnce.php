<?php

declare(strict_types=1);

namespace App\Agent\Grounding;

use App\Agent\Debug\ProviderDiagnostics;
use App\Decisions\DecisionResult;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;

/** Each bounded phase has a durable slot; retrying that phase never redispatches it. */
final class DecisionOnce
{
    public function run(?string $runId, string $signature, \Closure $call, string $slot = 'focused_decision', ?string $tenantId = null): DecisionResult
    {
        if ($runId === null) {
            return $call();
        }
        $diagnostics = app(ProviderDiagnostics::class);
        $diagnosticRun = $diagnostics->resolve($runId, $tenantId);
        $operation = $slot;
        if ($diagnosticRun?->usesSessionEvidence() && isset($diagnosticRun->result_json['session_context_gap'])
            && ! str_starts_with($slot, 'progressive_decision_')) {
            // A further authorized collection can change the facts that need validation.
            // Redeliveries within that same collection still share the durable decision.
            $depth = (int) ($diagnosticRun->result_json['session_research']['depth_floor']
                ?? $diagnosticRun->result_json['session_context_gap']['depth_floor'] ?? 0);
            $slot .= '_session_recovery_'.$depth;
        }
        $memo = DB::transaction(function () use ($runId, $signature, $slot, $operation): array {
            $run = AgentRun::query()->where('run_id', $runId)->lockForUpdate()->first();
            if ($run === null) {
                return ['ephemeral' => true]; // Unit-level/non-durable synthesizer use.
            }
            $payload = $run->result_json ?? [];
            if (isset($payload[$slot])) {
                return $payload[$slot];
            }
            if ($slot !== $operation && ($payload[$operation]['signature'] ?? null) === $signature) {
                return $payload[$operation]; // Includes compatible decisions recorded before scoped slots existed.
            }
            $payload[$slot] = ['signature' => $signature, 'status' => 'dispatched'];
            $run->forceFill(['result_json' => $payload])->save();
            return ['new' => true];
        });
        if (isset($memo['answers']) && ($memo['signature'] ?? '') === $signature) {
            return new DecisionResult($memo['id'], $memo['model'], $memo['answers'], $memo['usage'], $memo['latency_ms'], $memo['answer_errors'] ?? []);
        }
        if (! isset($memo['new']) && ! isset($memo['ephemeral'])) {
            $exception = new \RuntimeException('Semantic decision already dispatched; no automatic replay.');
            $span = $diagnostics->begin($diagnosticRun, 'validation.decision_dispatch', ['operation' => $slot]);
            $diagnostics->failure($diagnosticRun, $span, $exception, ['wait_reason' => 'already_dispatched_no_replay', 'error_handled' => true]);
            throw $exception;
        }
        $result = $diagnostics->measure($diagnosticRun, 'provider.decision', $call, $diagnostics->decisionMetadata($operation));
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
