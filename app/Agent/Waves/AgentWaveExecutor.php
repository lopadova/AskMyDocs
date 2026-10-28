<?php

declare(strict_types=1);

namespace App\Agent\Waves;

use App\Services\Chat\Reasoning\ResearchFailure;
use Illuminate\Console\Application;
use Illuminate\Support\Facades\Process;
use Laravel\SerializableClosure\SerializableClosure;

/** Separate PHP processes, not shared mutable tenant/DB state or extra queue workers. */
class AgentWaveExecutor
{
    public function run(array $tasks, bool $forceProcess = false, ?\Closure $diagnostics = null, ?float $timeoutSeconds = null): array
    {
        $observe = $diagnostics ?? static fn (string $phase, callable $operation, array $metadata = []) => $operation();
        $configuredTimeout = max(0.0, (float) config('agent.waves.timeout_seconds', 45));
        $duration = max(0.0, min($configuredTimeout, $timeoutSeconds ?? $configuredTimeout));
        $deadline = hrtime(true) + (int) ($duration * 1_000_000_000);
        $results = [];
        foreach (array_chunk($tasks, max(1, (int) config('agent.waves.concurrency', 10)), true) as $batch) {
            if (app()->runningUnitTests() && ! $forceProcess) {
                foreach ($batch as $key => $task) {
                    if (hrtime(true) >= $deadline) {
                        $results[$key] = new ResearchFailure('research_wave_timeout');
                        continue;
                    }
                    try {
                        $results[$key] = $observe('research.inline_task', $task,
                            ['research_flow_id' => $key, 'driver' => 'inline_test', 'error_handled' => true]);
                    } catch (\Throwable) {
                        $results[$key] = new ResearchFailure('research_branch_failed');
                    }
                }
                continue;
            }
            $processes = [];
            foreach ($batch as $key => $task) {
                $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
                if ($remaining <= 0) {
                    $results[$key] = new ResearchFailure('research_wave_timeout');
                    continue;
                }
                try {
                    $processes[$key] = $observe('research.process_dispatch', fn () => Process::path(base_path())
                        ->env(['LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task)))])
                        // Laravel accepts integer seconds; the monotonic deadline below enforces fractions.
                        ->timeout(max(1, (int) ceil($remaining)))
                        ->start(Application::formatCommandString('invoke-serialized-closure')),
                        ['research_flow_id' => $key, 'driver' => 'process', 'wait_reason' => 'process_start', 'error_handled' => true,
                            'timeout_seconds' => $remaining]);
                } catch (\Throwable) {
                    $results[$key] = new ResearchFailure('research_process_unavailable');
                }
            }
            // Observe every process while waiting so a slow first sibling cannot hide
            // another sibling's success when the shared deadline expires.
            while ($processes !== []) {
                foreach ($processes as $key => $process) {
                    try {
                        if ($process->running()) continue;
                        $results[$key] = $observe('research.process_wait', static function () use ($process) {
                            $result = $process->wait();
                            $output = explode("\x1f\x8b", $result->output(), 2)[0];
                            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
                            return $result->successful() && ($decoded['successful'] ?? false)
                                ? unserialize($decoded['result']) : new ResearchFailure('research_branch_failed');
                        }, ['research_flow_id' => $key, 'driver' => 'process', 'wait_reason' => 'process_result', 'error_handled' => true]);
                    } catch (\Throwable $exception) {
                        $process->stop(0);
                        $results[$key] = new ResearchFailure('research_process_error:'.$exception::class);
                    }
                    unset($processes[$key]);
                }
                if ($processes === []) break;
                if (hrtime(true) >= $deadline) {
                    foreach ($processes as $key => $process) {
                        // Check once more: completed siblings remain valid at the boundary.
                        try {
                            if (! $process->running()) {
                                $result = $process->wait();
                                $decoded = json_decode(explode("\x1f\x8b", $result->output(), 2)[0], true, 512, JSON_THROW_ON_ERROR);
                                $results[$key] = $result->successful() && ($decoded['successful'] ?? false)
                                    ? unserialize($decoded['result']) : new ResearchFailure('research_branch_failed');
                                continue;
                            }
                            $process->stop(0);
                        } catch (\Throwable) {
                            $process->stop(0);
                        }
                        $results[$key] = new ResearchFailure('research_wave_timeout');
                    }
                    break;
                }
                usleep((int) min(10_000, max(1, ($deadline - hrtime(true)) / 1000)));
            }
        }
        ksort($results);
        return $results;
    }
}
