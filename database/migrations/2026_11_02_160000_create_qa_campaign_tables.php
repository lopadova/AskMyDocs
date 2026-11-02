<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 50)->index();
            $table->string('project_key');
            $table->unsignedBigInteger('actor_id');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('budget_nanos');
            $table->unsignedBigInteger('spent_nanos')->default(0);
            $table->unsignedBigInteger('reserved_nanos')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('stop_reason', 80)->nullable();
            $table->json('manifest_json');
            $table->timestamps();
        });
        Schema::create('qa_inference_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 50)->index();
            $table->foreignUuid('campaign_id')->constrained('qa_campaigns')->restrictOnDelete();
            $table->uuid('run_uuid')->index();
            $table->uuid('scope_id')->index();
            $table->string('round', 16);
            $table->string('case_id', 40);
            $table->unsignedTinyInteger('turn');
            $table->string('path', 80);
            $table->string('requested_model', 160);
            $table->string('actual_model', 160)->nullable();
            $table->string('generation_id', 160)->nullable();
            $table->string('cost_source', 32)->nullable();
            $table->json('caller_classes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->char('request_sha256', 64);
            $table->unsignedBigInteger('reserved_nanos');
            $table->unsignedBigInteger('cost_nanos')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->index(['campaign_id', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_inference_attempts');
        Schema::dropIfExists('qa_campaigns');
    }
};
