<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_run_nodes')) {
            return;
        }

        Schema::create('flow_run_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 36);
            $table->unsignedInteger('sequence')->nullable(); // v1: step order; graph: topological index (display)
            $table->string('node_id');                       // v1: step_name; graph: GraphNode id
            $table->string('node_type');                     // 'legacy.step' for v1 steps + compiled legacy; real type for graph
            $table->string('handler')->nullable();           // resolved handler class (v1 parity)
            $table->string('status', 32)->index();           // NodeState->value
            $table->unsignedInteger('attempts')->default(0);
            $table->json('inputs')->nullable();              // resolved+redacted input port map (v1: step input)
            $table->json('outputs')->nullable();             // output port map (redacted) (v1: step output)
            $table->json('business_impact')->nullable();
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('dry_run_skipped')->default(false);
            $table->string('cache_hit')->nullable();         // null=n/a, content hash when served from cache
            $table->timestampTz('available_at')->nullable(); // retry/backoff gate
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampsTz();

            $table->unique(['run_id', 'node_id']);
            $table->index(['run_id', 'status']);
            $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_run_nodes');
    }
};
