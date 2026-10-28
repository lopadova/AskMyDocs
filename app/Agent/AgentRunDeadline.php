<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Debug\RunDiagnostics;
use App\Models\AgentDiagnosticSpan;
use App\Models\AgentRun;
use Throwable;

final class AgentRunDeadline
{
    /** @var list<array{tenant: string, id: int, start: \Carbon\CarbonInterface, timeout: float}> */
    private static array $invocations = [];

    public function within(AgentRun $run, \Carbon\CarbonInterface $start, float $timeout, callable $callback): mixed
    {
        self::$invocations[] = ['tenant' => $run->tenant_id, 'id' => $run->id, 'start' => $start->copy(), 'timeout' => max(30, $timeout)];
        try {
            return $callback();
        } finally {
            array_pop(self::$invocations);
        }
    }

    public function remaining(?AgentRun $run): float
    {
        if ($run === null) {
            return INF;
        }

        $timeout = max(30, (float) config('agent.job_timeout_seconds', 120));
        $start = $run->started_at ?? $run->created_at ?? now();
        $invocation = collect(array_reverse(self::$invocations))->first(
            fn (array $entry) => $entry['tenant'] === $run->tenant_id && $entry['id'] === $run->id,
        );
        if ($invocation !== null) {
            $start = $invocation['start'];
            $timeout = $invocation['timeout'];
        } else {
            try {
                $worker = AgentDiagnosticSpan::query()->forTenant($run->tenant_id)
                    ->where('agent_run_id', $run->id)->where('phase', 'worker.execute')
                    ->orderByDesc('started_at')->orderByDesc('created_at')->first();
                if ($worker !== null) {
                    $start = $worker->started_at ?? $start;
                    $recorded = $worker->metadata_json['timeout_seconds'] ?? null;
                    if (is_numeric($recorded) && (float) $recorded >= 30) {
                        $timeout = (float) $recorded;
                    }
                }
            } catch (Throwable) {
                // Diagnostics are best effort; use the run timestamps when unavailable.
            }
        }

        $elapsed = max(0, $start->diffInSeconds(now(), false));
        $safety = max(0, (float) config('agent.finalization.safety_seconds', 5));

        return max(0, $timeout - $elapsed - $safety);
    }

    public function canStart(?AgentRun $run, float $minimumSeconds): bool
    {
        return $this->remaining($run) >= max(0, $minimumSeconds);
    }

    public function skip(?AgentRun $run, string $operation, float $minimumSeconds): bool
    {
        $remaining = $this->remaining($run);
        if ($remaining >= max(0, $minimumSeconds)) {
            return false;
        }

        if ($run !== null) {
            $diagnostics = app(RunDiagnostics::class);
            $span = $diagnostics->begin($run, 'finalization.skipped', [
                'operation' => $operation, 'reason' => 'deadline',
                'remaining_seconds' => $remaining, 'required_seconds' => $minimumSeconds,
            ]);
            $diagnostics->end($run, $span);
        }

        return true;
    }
}
