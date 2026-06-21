<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `kb_ingest_batches` — one row per UI drag-and-drop upload batch.
 *
 * Tracks the staging → commit → ingest lifecycle for files uploaded via the
 * admin SPA modal. The batch exists BEFORE any queued job (during staging),
 * so neither `job_batches` nor `flow_runs` can represent it; a dedicated
 * tenant-scoped table is the right surface (precedent: admin_command_audits).
 *
 * `committed_at` is the single-use commit gate (R21): the commit transaction
 * flips status `staged → committing` AND stamps `committed_at` under the same
 * `lockForUpdate`, so two concurrent commits cannot both proceed.
 *
 * Tenant-aware (R30/R31): `tenant_id` defaults to 'default' (v3 back-compat),
 * every composite index starts with `tenant_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_ingest_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120);
            // Optional KB sub-folder the files land in (project root when null).
            $table->string('sub_path', 500)->nullable();
            // staged | committing | processing | completed |
            // completed_with_errors | cancelled | expired
            $table->string('status', 20)->default('staged')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // R21 — written inside the commit lock; non-null means "already committed".
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_ingest_batches');
    }
};
