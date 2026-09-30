<?php

declare(strict_types=1);

namespace App\Services\Chat\Reasoning;

use Illuminate\Console\Application;
use Illuminate\Support\Facades\Process;
use Laravel\SerializableClosure\SerializableClosure;

/** Separate PHP processes, not shared mutable tenant/DB state or extra queue workers. */
class ResearchFanout
{
    public function run(array $tasks, bool $forceProcess = false): array
    {
        $results = [];
        foreach (array_chunk($tasks, (int) config('reasoning.research_concurrency', 4), true) as $batch) {
            if (app()->runningUnitTests() && ! $forceProcess) {
                foreach ($batch as $key => $task) {
                    try {
                        $results[$key] = $task();
                    } catch (\Throwable) {
                        $results[$key] = new ResearchFailure('research_branch_failed');
                    }
                }
                continue;
            }
            $processes = [];
            // Start every task before waiting. Unlike a fail-fast pool, a crashed or
            // timed-out child must not discard successfully completed sibling results.
            foreach ($batch as $key => $task) {
                try {
                    $processes[$key] = Process::path(base_path())
                        ->env(['LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task)))])
                        ->timeout((int) config('reasoning.research_timeout_seconds', 180))
                        ->start(Application::formatCommandString('invoke-serialized-closure'));
                } catch (\Throwable) {
                    $results[$key] = new ResearchFailure('research_process_unavailable');
                }
            }
            foreach ($processes as $key => $process) {
                try {
                    $result = $process->wait();
                    $output = explode("\x1f\x8b", $result->output(), 2)[0];
                    $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
                    $results[$key] = $result->successful() && ($decoded['successful'] ?? false)
                        ? unserialize($decoded['result']) : new ResearchFailure('research_branch_failed');
                } catch (\Throwable $exception) {
                    $process->stop();
                    $results[$key] = new ResearchFailure('research_process_error:'.$exception::class);
                }
            }
        }
        ksort($results);
        return $results;
    }
}
