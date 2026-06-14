<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.11/P4 — Auto-Wiki indices (Karpathy `index.md` hub + AutoSci anchor map).
 *
 * One row per index artifact:
 *   - index_type='project'    + project_key=<key>  → a per-(tenant,project)
 *     roll-up describing that project's wiki (page counts by type, central
 *     concepts, recently-changed). Companion to the synthesized `project-index`
 *     canonical doc.
 *   - index_type='tenant_hub' + project_key='*'     → the per-tenant hub: the
 *     map across all the tenant's projects (the anchor the agentic retrieval
 *     starts from). Never cross-tenant (R30).
 *
 * Rebuildable from the markdown corpus at any time (it's a projection), so no
 * FK to knowledge_documents. Tenant-aware per R30/R31.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_wiki_indices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            // '*' sentinel for the tenant-level hub (no single project).
            $table->string('project_key', 120);
            // 'project' | 'tenant_hub'
            $table->string('index_type', 20);
            // Structured roll-up: { page_counts_by_type, concept_count,
            //   project_count?, projects?[], recently_changed[], built_at }.
            $table->json('payload_json');
            $table->timestamps();

            // One index artifact per (tenant, project, type). The tenant hub
            // uses project_key='*' so the unique still holds without nullable
            // semantics. R30/R31 — tenant_id leads the composite.
            $table->unique(['tenant_id', 'project_key', 'index_type'], 'uq_kb_wiki_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_wiki_indices');
    }
};
