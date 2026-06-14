<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.11/P8 — apply engine: the audit of applied change/delete suggestions.
 *
 * One row per concrete mutation derived from a `kb_doc_analyses` suggestion
 * (add a cross-reference edge, or deprecate an impacted doc). Append-only
 * forensic record (no `updated_at`); no FK to kb_doc_analyses / knowledge_
 * documents so the trail survives a hard delete (mirrors kb_canonical_audit).
 * Tenant-aware per R30/R31.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_doc_analysis_applications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120)->index();
            // The kb_doc_analyses row this application came from (nullable — an
            // operator may apply a one-off mutation outside an analysis).
            $table->unsignedBigInteger('analysis_id')->nullable()->index();
            // 'cross_reference' | 'impacted'
            $table->string('suggestion_type', 32);
            // The mutation performed, e.g. 'add_cross_reference' | 'deprecate'.
            $table->string('action', 64);
            $table->string('source_slug', 255)->nullable();
            $table->string('target_slug', 255)->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            // human user id ('admin:7'), 'system:autowiki-apply' for auto-apply.
            $table->string('applied_by', 100);
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'project_key', 'analysis_id'], 'idx_kb_apply_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_doc_analysis_applications');
    }
};
