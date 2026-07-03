<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the R38 E2E drain seam: POST /testing/drain-queue must run the
 * `queue:work` drain cleanly (no 500) and answer `{ drained: true }`. The E2E
 * `drainQueue()` helper relies on this to force async job-derived state (the
 * canonical graph a source-edit re-index rebuilds) to be deterministic before a
 * spec asserts on it. This unit-level guard catches a broken endpoint in the
 * fast PHPUnit gate instead of a 20-minute Playwright cycle.
 */
final class DrainQueueSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_drain_queue_endpoint_runs_the_worker_and_returns_ok(): void
    {
        $this->postJson('/testing/drain-queue')
            ->assertOk()
            ->assertJson(['drained' => true]);
    }
}
