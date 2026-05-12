<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\Workflow;
use Database\Seeders\BuiltInWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v4.7/W2 — BuiltInWorkflowSeeder tests.
 *
 * Asserts the seeder mints exactly 15 system templates and is
 * idempotent on a second run (no duplicates, same total).
 */
final class BuiltInWorkflowSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_exactly_15_system_workflows(): void
    {
        $this->seed(BuiltInWorkflowSeeder::class);

        $rows = Workflow::query()->where('is_system', true)->get();
        $this->assertCount(15, $rows, 'Expected exactly 15 built-in system workflows.');

        // Each must carry is_system=true and a null user_id.
        foreach ($rows as $row) {
            $this->assertTrue((bool) $row->is_system);
            $this->assertNull($row->user_id);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(BuiltInWorkflowSeeder::class);
        $this->seed(BuiltInWorkflowSeeder::class);

        $this->assertSame(15, Workflow::query()->where('is_system', true)->count());
    }
}
