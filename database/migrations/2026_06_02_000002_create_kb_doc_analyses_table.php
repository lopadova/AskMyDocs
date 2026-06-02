<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.7/W3–W4 — AI deep-analysis on document change.
 *
 * One row per analysis run: when a document is ingested or modified, an
 * async `AnalyzeDocumentChangeJob` asks the LLM to (a) suggest how to
 * strengthen the doc, (b) surface its cross-references with existing docs,
 * and (c) flag which OTHER docs this change makes obsolete / in need of
 * revision. The structured result lands here.
 *
 * No FK to `knowledge_documents` (mirrors `kb_canonical_audit`'s design):
 * the analysis is a forensic record that should survive a hard delete of
 * the doc it describes. Tenant-aware per R30/R31.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_doc_analyses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120)->index();
            $table->unsignedBigInteger('knowledge_document_id')->index();
            $table->string('doc_slug', 200)->nullable();
            // What triggered the analysis: 'ingested' | 'modified'.
            $table->string('trigger', 20);
            // Structured LLM output:
            //   { enhancement_suggestions: string[],
            //     cross_references: [{slug,title,why}],
            //     impacted_docs: [{slug,title,impact,suggested_action}] }
            $table->json('analysis_json');
            $table->unsignedInteger('suggestion_count')->default(0);
            $table->unsignedInteger('impacted_count')->default(0);
            $table->string('provider', 60)->nullable();
            $table->string('model', 120)->nullable();
            // 'completed' | 'failed'.
            $table->string('status', 20)->default('completed');
            $table->text('error')->nullable();
            $table->timestamps();

            // Bell/admin hot path: latest analyses per doc within a tenant.
            $table->index(['tenant_id', 'knowledge_document_id', 'created_at'], 'ix_kb_doc_analyses_tenant_doc_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_doc_analyses');
    }
};
