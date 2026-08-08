<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id')->unique();
            $table->string('tenant_id', 50)->index();
            $table->string('project_key', 120)->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('widget_identity_id')->nullable()->constrained('widget_identities')->nullOnDelete();
            $table->foreignId('widget_session_id')->nullable()->constrained('widget_sessions')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('actor_type', 32);
            $table->string('actor_id', 64)->nullable();
            $table->string('locale', 35);
            $table->string('timezone', 64);
            $table->string('status', 32)->default('queued')->index();
            $table->json('input_json')->nullable();
            $table->json('plan_json')->nullable();
            $table->json('budget_json')->nullable();
            $table->json('counters_json')->nullable();
            $table->json('result_json')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'channel', 'created_at'], 'idx_agent_runs_tenant_channel_created');
        });

        Schema::create('agent_run_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type', 80);
            $table->string('phase', 40)->nullable();
            $table->string('locale', 35);
            $table->string('message_key', 128)->nullable();
            $table->json('message_params')->nullable();
            $table->text('message')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
            $table->unique(['agent_run_id', 'sequence'], 'uq_agent_run_event_sequence');
            $table->index(['agent_run_id', 'created_at'], 'idx_agent_run_events_created');
        });

        Schema::create('agent_tool_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->unsignedInteger('logical_index');
            $table->string('tool_name', 128);
            $table->string('tool_kind', 32);
            $table->unsignedBigInteger('api_route_id')->nullable();
            $table->string('status', 32)->default('queued');
            $table->json('depends_on_json')->nullable();
            $table->json('arguments_json')->nullable();
            $table->json('result_meta_json')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->unsignedInteger('physical_request_count')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['agent_run_id', 'logical_index'], 'uq_agent_tool_logical_index');
            $table->index(['agent_run_id', 'status'], 'idx_agent_tool_run_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_executions');
        Schema::dropIfExists('agent_run_events');
        Schema::dropIfExists('agent_runs');
    }
};
