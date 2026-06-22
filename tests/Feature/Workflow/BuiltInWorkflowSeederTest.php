<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\Workflow;
use App\Support\TenantContext;
use Database\Seeders\BuiltInWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * v4.7/W2 — BuiltInWorkflowSeeder tests.
 *
 * Asserts the seeder mints exactly 16 system templates (v8.19/W4 added the
 * "Canonical KB Governance Audit" agentic report) and is idempotent on a second
 * run (no duplicates, same total).
 */
final class BuiltInWorkflowSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Copilot iter 18: reset TenantContext to 'default' so this
        // suite is deterministic regardless of which test file ran
        // first. Without this, an earlier suite that switched the
        // singleton to 'acme' would silently seed system workflows
        // into the wrong tenant — the count assertion would still
        // pass (16 rows exist) but the assertion that the seeder
        // wrote into the DEFAULT tenant would fail.
        Cache::flush();
        app(TenantContext::class)->set('default');
    }

    public function test_seeder_creates_exactly_16_system_workflows(): void
    {
        $this->seed(BuiltInWorkflowSeeder::class);

        $rows = Workflow::query()->where('is_system', true)->get();
        $this->assertCount(16, $rows, 'Expected exactly 16 built-in system workflows.');

        // Each must carry is_system=true, a null user_id, AND a
        // tenant_id matching the active TenantContext (default). The
        // tenant_id assertion is the deterministic check that proves
        // the setUp() reset took effect.
        foreach ($rows as $row) {
            $this->assertTrue((bool) $row->is_system);
            $this->assertNull($row->user_id);
            $this->assertSame('default', $row->tenant_id);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(BuiltInWorkflowSeeder::class);
        $this->seed(BuiltInWorkflowSeeder::class);

        $this->assertSame(16, Workflow::query()->where('is_system', true)->count());
    }
}
