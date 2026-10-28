<?php

namespace Tests\Unit\Agent;

use App\Services\Chat\Reasoning\ResearchFailure;
use App\Agent\Waves\AgentWaveExecutor;
use Tests\TestCase;

class AgentWaveExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Testbench's skeleton is not this application's Artisan entry point.
        app()->setBasePath(dirname(__DIR__, 3));
    }

    public function test_native_processes_overlap_and_failure_keeps_sibling_results(): void
    {
        config(['agent.waves.concurrency' => 3, 'agent.waves.timeout_seconds' => 15]);
        $tasks = [];
        for ($i = 0; $i < 2; $i++) {
            $tasks[$i] = static function () {
                $start = microtime(true);
                usleep(700000);
                return ['pid' => getmypid(), 'start' => $start, 'end' => microtime(true)];
            };
        }
        $tasks[2] = static function () { throw new \RuntimeException('Synthetic branch failure'); };
        $results = app(AgentWaveExecutor::class)->run($tasks, forceProcess: true);
        $this->assertIsArray($results[0], json_encode($results));
        $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
        $this->assertLessThan(min($results[0]['end'], $results[1]['end']), max($results[0]['start'], $results[1]['start']));
        $this->assertInstanceOf(ResearchFailure::class, $results[2]);
    }

    public function test_process_results_ignore_output_and_preserve_binary_values(): void
    {
        config(['agent.waves.concurrency' => 3, 'agent.waves.timeout_seconds' => 15]);
        $tasks = [];
        foreach (['documents', 'api'] as $kind) {
            $tasks[] = static function () use ($kind) {
                echo "bootstrap\x1b[31m noisy output\n";
                $start = microtime(true);
                usleep(700000);
                $value = new \App\Agent\Tools\AgentToolActionResult(
                    ['kind' => $kind, 'data' => "binary\0\xff", 'pid' => getmypid(), 'start' => $start, 'end' => microtime(true)], 1
                );
                echo "trailing output\n";
                return $value;
            };
        }
        $tasks[] = static fn () => null;
        $results = app(AgentWaveExecutor::class)->run($tasks, forceProcess: true);
        $this->assertInstanceOf(\App\Agent\Tools\AgentToolActionResult::class, $results[0]);
        $this->assertInstanceOf(\App\Agent\Tools\AgentToolActionResult::class, $results[1]);
        $this->assertSame("binary\0\xff", $results[0]->body['data']);
        $this->assertSame('documents', $results[0]->body['kind']);
        $this->assertSame('api', $results[1]->body['kind']);
        $this->assertNotSame($results[0]->body['pid'], $results[1]->body['pid']);
        $this->assertLessThan(min($results[0]->body['end'], $results[1]->body['end']), max($results[0]->body['start'], $results[1]->body['start']));
        $this->assertNull($results[2]);
    }

    public function test_timeout_does_not_discard_the_completed_sibling(): void
    {
        config(['agent.waves.concurrency' => 2, 'agent.waves.timeout_seconds' => 1]);
        $results = app(AgentWaveExecutor::class)->run([
            static function () { sleep(3); return 'late'; },
            static fn () => 'finished',
        ], forceProcess: true);
        $this->assertInstanceOf(ResearchFailure::class, $results[0]);
        $this->assertSame('finished', $results[1]);
    }
    public function test_next_wave_starts_only_after_all_previous_tasks_finish(): void
    {
        config(['agent.waves.concurrency' => 2, 'agent.waves.timeout_seconds' => 15]);
        $executor = app(AgentWaveExecutor::class);
        $first = $executor->run([
            static function () { usleep(200000); return microtime(true); },
            static function () { usleep(500000); return microtime(true); },
        ], forceProcess: true);
        $second = $executor->run([static fn () => microtime(true)], forceProcess: true);
        $this->assertIsFloat($first[0]);
        $this->assertIsFloat($first[1]);
        $this->assertIsFloat($second[0]);
        $this->assertGreaterThanOrEqual(max($first), $second[0]);
    }

    public function test_total_wave_deadline_stops_late_batch_and_preserves_finished_sibling(): void
    {
        config(['agent.waves.concurrency' => 2, 'agent.waves.timeout_seconds' => 15]);
        $marker = sys_get_temp_dir().'/wave-deadline-'.uniqid('', true);
        try {
            $start = microtime(true);
            $results = app(AgentWaveExecutor::class)->run([
                static function () { usleep(250000); return 'first'; },
                static function () { usleep(250000); return 'second'; },
                static function () { sleep(4); return 'too late'; },
                static fn () => 'finished sibling',
                static function () use ($marker) { file_put_contents($marker, 'started'); return 'must not run'; },
            ], forceProcess: true, timeoutSeconds: 1.2);
            $this->assertLessThan(3, microtime(true) - $start);
            $this->assertSame('first', $results[0]);
            $this->assertSame('second', $results[1]);
            $this->assertInstanceOf(ResearchFailure::class, $results[2]);
            $this->assertSame('finished sibling', $results[3]);
            $this->assertInstanceOf(ResearchFailure::class, $results[4]);
            $this->assertFileDoesNotExist($marker);
        } finally {
            if (is_file($marker)) unlink($marker);
        }
    }

}
