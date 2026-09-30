<?php

namespace Tests\Unit\Chat;

use App\Services\Chat\Reasoning\ResearchFailure;
use App\Services\Chat\Reasoning\ResearchFanout;
use Tests\TestCase;

class ResearchFanoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Testbench's skeleton is not this application's Artisan entry point.
        app()->setBasePath(dirname(__DIR__, 3));
    }

    public function test_native_processes_overlap_and_failure_keeps_sibling_results(): void
    {
        config(['reasoning.research_concurrency' => 3, 'reasoning.research_timeout_seconds' => 15]);
        $tasks = [];
        for ($i = 0; $i < 2; $i++) {
            $tasks[$i] = static function () {
                $start = microtime(true);
                usleep(700000);
                return ['pid' => getmypid(), 'start' => $start, 'end' => microtime(true)];
            };
        }
        $tasks[2] = static function () { throw new \RuntimeException('Synthetic branch failure'); };
        $results = app(ResearchFanout::class)->run($tasks, forceProcess: true);
        $this->assertIsArray($results[0], json_encode($results));
        $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
        $this->assertLessThan(min($results[0]['end'], $results[1]['end']), max($results[0]['start'], $results[1]['start']));
        $this->assertInstanceOf(ResearchFailure::class, $results[2]);
    }

    public function test_timeout_does_not_discard_the_completed_sibling(): void
    {
        config(['reasoning.research_concurrency' => 2, 'reasoning.research_timeout_seconds' => 1]);
        $results = app(ResearchFanout::class)->run([
            static function () { sleep(3); return 'late'; },
            static fn () => 'finished',
        ], forceProcess: true);
        $this->assertInstanceOf(ResearchFailure::class, $results[0]);
        $this->assertSame('finished', $results[1]);
    }
}
