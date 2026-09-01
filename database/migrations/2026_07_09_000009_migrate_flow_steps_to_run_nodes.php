<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-only data migration: copy any existing flow_steps rows into the
 * unified flow_run_nodes table (mapping step_name -> node_id, input -> inputs,
 * output -> outputs, node_type = 'legacy.step'), then retire flow_steps.
 *
 * Safe on hosts that never published flow_steps (guarded on hasTable) and
 * re-runnable: rows are copied with insertOrIgnore keyed by the unique
 * (run_id, node_id), so a re-run after a mid-copy interruption skips already-
 * copied rows instead of aborting; once flow_steps is dropped it is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_steps') || ! Schema::hasTable('flow_run_nodes')) {
            return;
        }

        DB::table('flow_steps')->orderBy('id')->chunk(500, function ($rows): void {
            $mapped = [];

            foreach ($rows as $row) {
                $mapped[] = [
                    'run_id' => $row->run_id,
                    'sequence' => $row->sequence,
                    'node_id' => $row->step_name,
                    'node_type' => 'legacy.step',
                    'handler' => $row->handler,
                    'status' => $row->status,
                    'attempts' => 0,
                    'inputs' => $row->input,
                    'outputs' => $row->output,
                    'business_impact' => $row->business_impact,
                    'error_class' => $row->error_class,
                    'error_message' => $row->error_message,
                    'dry_run_skipped' => $row->dry_run_skipped,
                    'cache_hit' => null,
                    'available_at' => null,
                    'started_at' => $row->started_at,
                    'finished_at' => $row->finished_at,
                    'duration_ms' => $row->duration_ms,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            }

            if ($mapped !== []) {
                // insertOrIgnore (not insert) so a re-run after a mid-chunk
                // interruption — some rows already copied, flow_steps not yet
                // dropped — skips the already-present (run_id, node_id) rows
                // instead of aborting on the unique constraint.
                DB::table('flow_run_nodes')->insertOrIgnore($mapped);
            }
        });

        Schema::dropIfExists('flow_steps');
    }

    /**
     * Forward-only: flow_steps is retired by design. Rolling back does not
     * resurrect the legacy table (the base create migration would recreate an
     * empty one); node rows remain in flow_run_nodes.
     */
    public function down(): void
    {
        // Intentionally a no-op — the unified persistence model is one-way.
    }
};
