<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.7/W1 — Synonym Expansion.
 *
 * Per-(tenant, project) synonym groups. Each row anchors one `term`
 * (already lowercased by the controller/model) to a list of `synonyms`.
 * At retrieval time {@see App\Services\Kb\Retrieval\SynonymExpander}
 * expands a query bidirectionally: a query mentioning ANY member of the
 * group (the term OR one of its synonyms) also searches every OTHER
 * member, so industry jargon / internal acronyms / product codenames
 * connect to their plain-language equivalents even when the embedding
 * model has never seen the in-house term.
 *
 * Tenant-scoped per R30/R31: the composite unique starts with
 * `tenant_id`, so two tenants — and two projects within one tenant —
 * can legitimately register the same `term` with different synonyms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_synonyms', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120)->index();
            // Lowercased anchor term (single word or short phrase).
            $table->string('term', 200);
            // List<string> of equivalent terms/phrases (lowercased).
            $table->json('synonyms');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // R30 — uniqueness is per (tenant_id, project_key, term).
            $table->unique(['tenant_id', 'project_key', 'term'], 'uq_kb_synonyms_tenant_project_term');
            // Hot-path lookup: the expander loads all enabled rows for the
            // active (tenant, project) on each query (cached); this index
            // keeps that scan covering.
            $table->index(['tenant_id', 'project_key', 'enabled'], 'ix_kb_synonyms_tenant_project_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_synonyms');
    }
};
