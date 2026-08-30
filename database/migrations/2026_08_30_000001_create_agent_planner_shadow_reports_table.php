<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_planner_shadow_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('iteration');
            $table->string('tenant_id', 50);
            $table->string('project_key', 120)->nullable();
            $table->string('mode', 20);
            $table->string('status', 32);
            $table->string('capability_hash', 64);
            $table->unsignedInteger('capability_count');
            $table->unsignedInteger('capability_bytes');
            $table->json('candidate_tools_json')->nullable();
            $table->json('route_json')->nullable();
            $table->json('classic_plan_json')->nullable();
            $table->json('capability_plan_json')->nullable();
            $table->json('comparison_json')->nullable();
            $table->unsignedInteger('router_latency_ms')->nullable();
            $table->unsignedInteger('planner_latency_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->boolean('fallback_used')->default(false);
            $table->string('error_code', 80)->nullable();
            $table->timestamps();

            $table->unique(['agent_run_id', 'iteration'], 'uq_agent_planner_shadow_iteration');
            $table->index(['tenant_id', 'created_at'], 'idx_agent_planner_shadow_tenant_time');
            $table->index(['tenant_id', 'status'], 'idx_agent_planner_shadow_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_planner_shadow_reports');
    }
};
