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
 * laravel-flow persistence layer (flow_runs / flow_run_nodes / flow_audit
 * / flow_approvals / flow_webhook_outbox + tenant_id) without each prod
 * migration's per-driver gymnastics. All five flow_* tables are created
 * below to keep the test schema aligned with production: even though
 * IngestDocumentFlow only exercises flow_runs / flow_run_nodes / flow_audit,
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

            // v2 graph-engine columns. All nullable: a v1-style linear run
            // leaves every one of them null.
            $table->unsignedInteger('definition_version')->nullable();
            $table->string('definition_checksum', 64)->nullable();
            $table->string('engine', 16)->nullable();
            $table->unsignedInteger('nodes_total')->nullable();
            $table->unsignedInteger('nodes_completed')->nullable();
            $table->unsignedInteger('nodes_failed')->nullable();
            $table->json('graph')->nullable();
            // Not optional despite being nullable: FlowEngine writes
            // 'subject' into every run-insert attribute array with no
            // column guard, so omitting it fails every Flow::execute().
            $table->string('subject')->nullable()->index();

            $table->index(['finished_at', 'id']);
            $table->unique(['tenant_id', 'idempotency_key'], 'flow_runs_tenant_idempotency_unique');
        });

        // v2 replaced the per-step table with a run-node graph. Column
        // renames: step_name -> node_id, input -> inputs, output -> outputs.
        // tenant_id is a HOST column: neither the package blueprint nor the
        // package's own conversion migration provides it.
        Schema::create('flow_run_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('run_id', 36);
            // Nullable in v2 (a graph node has no linear order), where the
            // v1 mirror had it NOT NULL. That divergence was real, not
            // cosmetic: the test schema promised a constraint production
            // does not have.
            $table->unsignedInteger('sequence')->nullable();
            $table->string('node_id');
            $table->string('node_type');
            $table->string('handler')->nullable();
            $table->string('status', 32)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('inputs')->nullable();
            $table->json('outputs')->nullable();
            $table->json('business_impact')->nullable();
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('dry_run_skipped')->default(false);
            $table->string('cache_hit')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            // Deliberately NOT tenant-prefixed, unlike the R31 default:
            // createOrUpdate() upserts with ON CONFLICT (run_id, node_id),
            // which errors without an index on exactly those two columns.
            // run_id is a UUID owned by one tenant, so the pair is already
            // transitively tenant-disjoint and stricter than a scoped unique.
            $table->unique(['run_id', 'node_id']);
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

        // The three graph-only tables. The host publishes their migrations,
        // so production has them; mirroring them here keeps this file's claim
        // of parity true rather than aspirational.
        //
        // None carries tenant_id, and that is deliberate, not an oversight:
        // the host never invokes the graph executor, so all three stay empty.
        // Adding an unpopulated tenant column would be worse than leaving it
        // out — it would look handled while every future row silently took the
        // default tenant. GraphExecutorNotAdoptedTest guards the assumption, so
        // whoever enables graph execution has to answer the tenancy question
        // first.
        Schema::create('flow_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft')->index();
            $table->json('graph');
            $table->string('checksum', 64)->index();
            $table->string('signature', 128)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'version']);
            $table->index(['name', 'status']);
        });

        Schema::create('flow_node_children', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 36);
            $table->string('parent_node_id');
            $table->string('child_run_id', 36)->nullable();
            $table->unsignedInteger('child_index');
            $table->string('status', 32);
            $table->string('child_flow');
            $table->unsignedInteger('child_version')->nullable();
            $table->json('input')->nullable();
            $table->json('outputs')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'parent_node_id', 'child_index']);
            $table->unique('child_run_id');
            $table->index(['run_id', 'parent_node_id', 'status']);
            $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
        });

        Schema::create('flow_node_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('content_hash', 64)->unique();
            $table->string('node_type');
            $table->json('outputs');
            $table->json('business_impact')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
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
        Schema::dropIfExists('flow_node_cache');
        Schema::dropIfExists('flow_node_children');
        Schema::dropIfExists('flow_definitions');
        Schema::dropIfExists('flow_audit');
        Schema::dropIfExists('flow_webhook_outbox');
        Schema::dropIfExists('flow_approvals');
        Schema::dropIfExists('flow_run_nodes');
        Schema::dropIfExists('flow_runs');
    }
};
