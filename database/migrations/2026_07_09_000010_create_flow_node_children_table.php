<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_node_children')) {
            return;
        }

        Schema::create('flow_node_children', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 36);              // parent run
            $table->string('parent_node_id');          // the fan-out / sub-flow node in the parent graph
            $table->string('child_run_id', 36)->nullable(); // spawned child run; null while `pending` (windowing)
            $table->unsignedInteger('child_index');    // position in the fan-out (ordered join)
            $table->string('status', 32);              // pending | running | terminal RunState->value
            $table->string('child_flow');              // published child flow name (to spawn a pending item)
            $table->unsignedInteger('child_version')->nullable(); // pinned child version, null = latest published
            $table->json('input')->nullable();         // the item's child-run input (redacted), for lazy spawn
            $table->json('outputs')->nullable();       // child run output (redacted), aggregated on join
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['run_id', 'parent_node_id', 'child_index']);
            // One ledger row per spawned child run (1:1); nullable so multiple
            // still-`pending` rows (no run yet) can coexist.
            $table->unique('child_run_id');
            $table->index(['run_id', 'parent_node_id', 'status']);
            $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_node_children');
    }
};
