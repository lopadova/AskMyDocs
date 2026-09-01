<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives flow_run_nodes the tenant column neither the package nor its own
 * conversion migration provides, and stamps the converted history correctly.
 *
 * laravel-flow v2 replaced flow_steps with flow_run_nodes. Two gaps meet here:
 *
 * 1. The package blueprint has no tenant_id — the package is tenant-agnostic
 *    by design — and the host migration that added the column to the other
 *    five flow tables (2026_05_09_146000) lists them by hand, a list written
 *    when this table did not exist. Editing THAT list would be a dead end in
 *    both directions: it has already run in production and will not re-run,
 *    and on a fresh install it runs before flow_run_nodes exists, so its
 *    hasTable guard skips it.
 *
 * 2. The package's conversion migration (2026_07_09_000009) copies every
 *    flow_steps row into flow_run_nodes WITHOUT tenant_id, then drops the
 *    source table.
 *
 * The column is added NULLABLE WITH NO DEFAULT, deliberately. A
 * default('default') here is the exact mechanism by which every historical row
 * of every tenant becomes readable by the `default` tenant — and it is
 * undetectable afterwards, because a legitimately-default row and a
 * mis-stamped one are byte-identical. NULL is distinguishable, invisible to
 * `where tenant_id = ?`, and repairable. Fail closed, then repair, then
 * tighten.
 *
 * The tenant comes from flow_runs, never from the dropped flow_steps.tenant_id:
 * the invariant that has to hold is that a node is visible exactly when its run
 * is visible, and the admin authorizer already gates every row action on
 * flow_runs.tenant_id. Deriving from the run makes both failure directions —
 * an invisible node under a visible run, a visible node under an invisible one
 * — impossible by construction. It also survives the source table being gone.
 *
 * Ordering matters beyond correctness. Because this runs AFTER the package
 * conversion, tenant_id does not exist while that migration is inserting, so
 * its insertOrIgnore cannot silently swallow a NOT NULL violation — which it
 * would do on SQLite and MySQL, discarding history and then dropping the
 * source table, with green output. Adding a NOT NULL tenant column BEFORE the
 * conversion is the shape to avoid.
 *
 * On PostgreSQL, DDL is transactional and Laravel wraps the migration, so
 * add + backfill + verify + tighten commit or roll back as one.
 */
return new class extends Migration
{
    private const TABLE = 'flow_run_nodes';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->addNullableTenantColumn();
        $this->backfillFromRuns();
        $this->tightenToHostShape();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'tenant_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }

    private function addNullableTenantColumn(): void
    {
        if (Schema::hasColumn(self::TABLE, 'tenant_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 50)->nullable()->index();
        });
    }

    /**
     * Stamp every node with its run's tenant.
     *
     * Chunked in PHP rather than a correlated UPDATE ... FROM because that
     * syntax diverges across drivers. Idempotent and re-runnable: it assigns
     * the same value on every pass, so an interrupted migration can simply be
     * run again.
     */
    private function backfillFromRuns(): void
    {
        if (! Schema::hasTable('flow_runs')) {
            return;
        }

        DB::table('flow_runs')
            ->select('id', 'tenant_id')
            ->orderBy('id')
            ->chunk(500, function ($runs): void {
                $byTenant = [];

                foreach ($runs as $run) {
                    $byTenant[(string) $run->tenant_id][] = $run->id;
                }

                foreach ($byTenant as $tenantId => $runIds) {
                    DB::table(self::TABLE)
                        ->whereIn('run_id', $runIds)
                        ->update(['tenant_id' => $tenantId]);
                }
            });
    }

    /**
     * Reach the R31 shape, refusing to guess.
     *
     * A row still NULL here has no run to inherit from, which means the
     * conversion produced something the FK should have prevented. Stamping it
     * 'default' would hide a real inconsistency inside a tenant that can read
     * it, so this throws instead and names the count.
     */
    private function tightenToHostShape(): void
    {
        $unattributed = DB::table(self::TABLE)->whereNull('tenant_id')->count();

        if ($unattributed > 0) {
            throw new RuntimeException(sprintf(
                '%d %s row(s) have no tenant after the backfill. Every node should '
                .'inherit its run\'s tenant, so these have no matching flow_runs row. '
                .'Resolve them before migrating — stamping them with the default '
                .'tenant would make them readable by it.',
                $unattributed,
                self::TABLE,
            ));
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 50)->default('default')->nullable(false)->change();
        });
    }
};
