<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Exercises the backfill in 2026_10_02_000008_add_tenant_id_to_flow_run_nodes.
 *
 * The other tests in this directory assert the resulting schema, which the
 * test-schema mirror already provides. That is not enough here: the risk in
 * this migration is not the column, it is whether historical rows converted
 * from flow_steps end up attributed to the RIGHT tenant. So each test
 * rebuilds flow_run_nodes in its pre-migration shape — no tenant_id, exactly
 * what the package blueprint and the package conversion leave behind — seeds
 * rows for two tenants, and runs the real migration.
 */
final class AddTenantIdToFlowRunNodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_converted_node_inherits_its_own_runs_tenant(): void
    {
        $this->rebuildWithoutTenantColumn();
        $this->seedRun('run-acme', 'acme');
        $this->seedRun('run-globex', 'globex');
        $this->seedNode('run-acme', 'parse-markdown');
        $this->seedNode('run-acme', 'chunk-document');
        $this->seedNode('run-globex', 'parse-markdown');

        $this->runMigration();

        $this->assertSame('acme', $this->tenantOf('run-acme', 'parse-markdown'));
        $this->assertSame('acme', $this->tenantOf('run-acme', 'chunk-document'));
        $this->assertSame('globex', $this->tenantOf('run-globex', 'parse-markdown'));
    }

    public function test_one_tenants_nodes_are_never_attributed_to_another(): void
    {
        // The failure this guards is not "some rows are wrong" but the specific
        // shape a default('default') column produces: every historical row of
        // every tenant readable by one of them, indistinguishable afterwards
        // from a legitimately-default row.
        $this->rebuildWithoutTenantColumn();
        $this->seedRun('run-acme', 'acme');
        $this->seedRun('run-globex', 'globex');
        $this->seedNode('run-acme', 'secret-step');
        $this->seedNode('run-globex', 'other-step');

        $this->runMigration();

        $visibleToGlobex = DB::table('flow_run_nodes')->where('tenant_id', 'globex')->pluck('node_id')->all();

        $this->assertSame(['other-step'], $visibleToGlobex);
        $this->assertSame(0, DB::table('flow_run_nodes')->where('tenant_id', 'default')->count());
    }

    public function test_the_backfill_is_rerunnable(): void
    {
        $this->rebuildWithoutTenantColumn();
        $this->seedRun('run-acme', 'acme');
        $this->seedNode('run-acme', 'parse-markdown');

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(1, DB::table('flow_run_nodes')->count());
        $this->assertSame('acme', $this->tenantOf('run-acme', 'parse-markdown'));
    }

    public function test_it_refuses_to_guess_a_tenant_for_a_node_with_no_run(): void
    {
        $this->rebuildWithoutTenantColumn();
        $this->seedNode('run-that-does-not-exist', 'orphan-step');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/have no tenant after the backfill/');

        $this->runMigration();
    }

    public function test_a_fresh_install_with_no_rows_still_reaches_the_host_shape(): void
    {
        $this->rebuildWithoutTenantColumn();

        $this->runMigration();

        $this->assertTrue(Schema::hasColumn('flow_run_nodes', 'tenant_id'));

        // Proves the column is usable with the host default rather than merely
        // present: an insert omitting tenant_id must land on 'default'.
        $this->seedRun('run-acme', 'acme');
        $this->seedNode('run-acme', 'later-step');

        $this->assertSame('default', $this->tenantOf('run-acme', 'later-step'));
    }

    private function runMigration(): void
    {
        // Not database_path(): under Testbench that resolves into the
        // skeleton app, not this repository. Same idiom the MCP roster
        // test uses to reach real host files.
        $migration = require dirname(__DIR__, 3)
            .'/database/migrations/2026_10_02_000008_add_tenant_id_to_flow_run_nodes.php';

        $migration->up();
    }

    /**
     * Recreate flow_run_nodes exactly as the package leaves it: the v2
     * blueprint, with no tenant column.
     */
    private function rebuildWithoutTenantColumn(): void
    {
        Schema::dropIfExists('flow_run_nodes');

        Schema::create('flow_run_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 36);
            $table->unsignedInteger('sequence')->nullable();
            $table->string('node_id');
            $table->string('node_type');
            $table->string('status', 32)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('inputs')->nullable();
            $table->json('outputs')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'node_id']);
        });
    }

    private function seedRun(string $runId, string $tenantId): void
    {
        DB::table('flow_runs')->insert([
            'id' => $runId,
            'tenant_id' => $tenantId,
            'definition_name' => 'kb.test',
            'status' => 'succeeded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedNode(string $runId, string $nodeId): void
    {
        DB::table('flow_run_nodes')->insert([
            'run_id' => $runId,
            'node_id' => $nodeId,
            'node_type' => 'legacy.step',
            'status' => 'succeeded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tenantOf(string $runId, string $nodeId): ?string
    {
        $value = DB::table('flow_run_nodes')
            ->where('run_id', $runId)
            ->where('node_id', $nodeId)
            ->value('tenant_id');

        return $value === null ? null : (string) $value;
    }
}
