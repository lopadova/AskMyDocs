<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.2/W2 — TEST mirror of the production migrations:
 *   - database/migrations/2026_05_09_145342_create_laravel_flow_tables.php
 *   - database/migrations/2026_05_09_145343_add_replay_lineage_to_laravel_flow_runs.php
 *   - database/migrations/2026_05_09_145344_create_laravel_flow_approval_and_webhook_tables.php
 *   - database/migrations/2026_05_09_145345_add_previous_token_hash_to_flow_approvals.php
 *   - database/migrations/2026_05_09_146000_add_tenant_id_to_flow_tables.php
 *
 * Combined here so SQLite tests under Orchestra Testbench can boot the
 * laravel-flow persistence layer (flow_runs / flow_steps / flow_audit
 * / flow_approvals / flow_webhook_outbox + tenant_id) without each prod
 * migration's per-driver gymnastics. All five flow_* tables are created
 * below to keep the test schema aligned with production: even though
 * IngestDocumentFlow only exercises flow_runs / flow_steps / flow_audit,
 * sub-PR 3c/3d additions and any package-level integration test that
 * touches approvals or webhook outbox will boot against the same fixture
 * without a follow-up migration.
 *
 * Per Copilot PR #115 review iteration 1 — the previous header comment
 * claimed flow_approvals / flow_webhook_outbox were NOT created, but
 * they ARE created below. Brought the comment in line with the schema
 * so future readers don't trust the doc over the code.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('flow_runs', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('tenant_id', 50)->default('default')->index();
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
            $table->string('idempotency_key')->nullable();
            $table->string('replayed_from_run_id', 36)->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['finished_at', 'id']);
            $table->unique(['tenant_id', 'idempotency_key'], 'flow_runs_tenant_idempotency_unique');
        });

        Schema::create('flow_steps', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
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
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'step_name']);
            $table->index(['run_id', 'status']);
            $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
        });

        Schema::create('flow_approvals', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('run_id', 36);
            $table->string('step_name');
            $table->string('status', 32)->index();
            $table->string('token_hash', 64)->unique();
            $table->string('previous_token_hash', 64)->nullable()->unique();
            $table->json('payload')->nullable();
            $table->json('actor')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
        });

        Schema::create('flow_webhook_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('run_id', 36)->nullable()->index();
            $table->string('approval_id', 36)->nullable()->index();
            $table->string('event')->index();
            $table->string('status', 32)->index();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['run_id', 'event']);
            $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
            $table->foreign('approval_id')->references('id')->on('flow_approvals')->nullOnDelete();
        });

        Schema::create('flow_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('run_id', 36)->index();
            $table->string('step_name')->nullable()->index();
            $table->string('event')->index();
            $table->json('payload')->nullable();
            $table->json('business_impact')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('created_at')->nullable();

            $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_audit');
        Schema::dropIfExists('flow_webhook_outbox');
        Schema::dropIfExists('flow_approvals');
        Schema::dropIfExists('flow_steps');
        Schema::dropIfExists('flow_runs');
    }
};
