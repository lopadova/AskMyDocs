<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tools', function (Blueprint $t): void {
            $t->id();
            $t->string('tenant_id');
            $t->string('project_key')->default('');
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('status')->default('draft');
            $t->boolean('publication_intent')->default(false);
            $t->boolean('manual_disabled')->default(false);
            $t->unsignedInteger('failure_threshold')->default(3);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->unsignedBigInteger('current_revision_id')->nullable();
            $t->string('content_hash', 64)->nullable();
            $t->unsignedInteger('verification_generation')->default(0);
            $t->string('verified_scope', 64)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'project_key', 'name']);
            $t->index(['tenant_id', 'status']);
        });
        Schema::create('api_tool_revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('api_tool_id')->constrained('api_tools')->cascadeOnDelete();
            $t->string('tenant_id');
            $t->unsignedInteger('number');
            $t->longText('definition'); // encrypted:array, immutable
            $t->string('definition_hash', 64);
            $t->timestamp('created_at');
            $t->unique(['api_tool_id', 'number']);
        });
        Schema::create('api_tool_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('tenant_id');
            $t->string('project_key')->default('');
            $t->foreignId('api_tool_id')->constrained('api_tools');
            $t->foreignId('api_tool_revision_id')->constrained('api_tool_revisions');
            $t->uuid('flow_run_id')->nullable()->unique();
            $t->string('status')->default('queued');
            $t->string('mode')->default('production');
            $t->string('content_hash', 64);
            $t->longText('snapshot');
            $t->longText('context');
            $t->longText('inputs');
            $t->longText('outputs')->nullable();
            $t->longText('continuation')->nullable();
            $t->unsignedInteger('physical_requests')->default(0);
            $t->unsignedInteger('request_limit');
            $t->string('error_code')->nullable();
            $t->timestamp('deadline_at');
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('resumed_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'api_tool_id', 'status']);
        });
        Schema::create('api_tool_steps', function (Blueprint $t): void {
            $t->id();
            $t->uuid('api_tool_run_id');
            $t->foreign('api_tool_run_id')->references('id')->on('api_tool_runs')->cascadeOnDelete();
            $t->string('tenant_id');
            $t->string('step_id');
            $t->string('status')->default('pending');
            $t->longText('inputs')->nullable();
            $t->longText('output')->nullable();
            $t->longText('diagnostics')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unique(['api_tool_run_id', 'step_id']);
        });
        Schema::create('api_tool_verifications', function (Blueprint $t): void {
            $t->id();
            $t->string('tenant_id');
            $t->foreignId('api_tool_id')->constrained('api_tools')->cascadeOnDelete();
            $t->foreignId('api_tool_revision_id')->constrained('api_tool_revisions');
            $t->string('content_hash', 64);
            $t->string('scope_hash', 64);
            $t->string('tests_hash', 64);
            $t->unsignedInteger('generation');
            $t->string('mode');
            $t->string('status')->default('pending');
            $t->longText('results')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('completed_at')->nullable();
            $t->index(['api_tool_id', 'content_hash', 'status']);
        });
        Schema::create('api_tool_state_events', function (Blueprint $t): void {
            $t->id();
            $t->string('tenant_id');
            $t->foreignId('api_tool_id')->constrained('api_tools')->cascadeOnDelete();
            $t->string('from_status');
            $t->string('to_status');
            $t->string('reason');
            $t->string('dedup_key', 64)->unique();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['api_tool_state_events', 'api_tool_verifications', 'api_tool_steps', 'api_tool_runs', 'api_tool_revisions', 'api_tools'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
