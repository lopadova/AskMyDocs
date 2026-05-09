<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_runs', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('definition_name')->index();
            $table->string('status', 32)->index();
            $table->boolean('dry_run')->default(false);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('business_impact')->nullable();
            $table->string('failed_step')->nullable();
            $table->boolean('compensated')->default(false);
            $table->string('compensation_status', 32)->nullable()->index();
            $table->string('correlation_id')->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampsTz();

            $table->index(['finished_at', 'id']);
        });

        Schema::create('flow_steps', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 36);
            $table->unsignedInteger('sequence');
            $table->string('step_name');
            $table->string('handler')->nullable();
            $table->string('status', 32)->index();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('business_impact')->nullable();
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('dry_run_skipped')->default(false);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampsTz();

            $table->unique(['run_id', 'step_name']);
            $table->index(['run_id', 'status']);
            $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
        });

        Schema::create('flow_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 36)->index();
            $table->string('step_name')->nullable()->index();
            $table->string('event')->index();
            $table->json('payload')->nullable();
            $table->json('business_impact')->nullable();
            $table->timestampTz('occurred_at')->nullable()->index();
            $table->timestampTz('created_at')->nullable();

            $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_audit');
        Schema::dropIfExists('flow_steps');
        Schema::dropIfExists('flow_runs');
    }
};
