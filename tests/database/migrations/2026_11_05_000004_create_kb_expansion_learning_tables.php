<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W7 (ADR 0035 addendum — expansion learning loop). Three tenant-aware tables
 * (R31: `tenant_id` first in every composite index):
 *
 *  - kb_expansion_events   one row per expansion that ran: the PII-masked query,
 *                          the typed variants and what each variant type
 *                          contributed to the final ranking (the reward signal).
 *  - kb_expansion_tunings  proposals derived from the events (per-type weights +
 *                          curated few-shot examples), with the benchmark gate
 *                          result and the human decision. At most one `applied`
 *                          row per (tenant, project) — enforced by the service.
 *  - kb_expansion_models   fine-tuning runs (export or provider job) and the
 *                          candidate model they produced; promotion is human.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kb_expansion_events')) {
            Schema::create('kb_expansion_events', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 50)->default('default')->index();
                $table->string('project_key', 120)->nullable();
                $table->char('query_hash', 64);
                $table->string('masked_query', 500);
                $table->string('source', 20);
                $table->json('expansion');
                $table->json('list_stats');
                $table->json('top_chunk_ids');
                $table->boolean('refused')->default(false);
                $table->smallInteger('feedback')->nullable();
                $table->unsignedBigInteger('feedback_by')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'project_key', 'created_at'], 'idx_kb_exp_events_tenant_project_time');
            });
        }

        if (! Schema::hasTable('kb_expansion_tunings')) {
            Schema::create('kb_expansion_tunings', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 50)->default('default')->index();
                $table->string('project_key', 120);
                $table->string('status', 20)->default('proposed');
                $table->json('weights');
                $table->json('few_shot');
                $table->json('evidence');
                $table->json('gate')->nullable();
                $table->string('proposed_by', 120);
                $table->string('decided_by', 120)->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'project_key', 'status'], 'idx_kb_exp_tunings_tenant_project_status');
            });
        }

        if (! Schema::hasTable('kb_expansion_models')) {
            Schema::create('kb_expansion_models', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 50)->default('default')->index();
                $table->string('project_key', 120)->nullable();
                $table->string('backend', 20);
                $table->string('base_model', 120);
                $table->string('dataset_path', 500)->nullable();
                $table->unsignedInteger('dataset_rows')->default(0);
                $table->string('provider_file_id', 120)->nullable();
                $table->string('provider_job_id', 120)->nullable();
                $table->string('model_id', 200)->nullable();
                $table->string('status', 20);
                $table->string('failure_reason', 120)->nullable();
                $table->json('gate')->nullable();
                $table->string('created_by', 120);
                $table->string('promoted_by', 120)->nullable();
                $table->timestamp('promoted_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'status'], 'idx_kb_exp_models_tenant_status');
                $table->string('retired_by', 120)->nullable();
                $table->timestamp('retired_at')->nullable();
            });
        }

        // R21 — the "one applied tuning per (tenant, project)" and "one promoted
        // model per tenant" invariants are DB-enforced where the engine supports
        // partial indexes (PostgreSQL); the service also serialises with a scope lock.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_kb_exp_tunings_one_applied ON kb_expansion_tunings (tenant_id, project_key) WHERE status = 'applied'");
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_kb_exp_models_one_promoted ON kb_expansion_models (tenant_id) WHERE status = 'promoted'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_expansion_models');
        Schema::dropIfExists('kb_expansion_tunings');
        Schema::dropIfExists('kb_expansion_events');
    }
};
