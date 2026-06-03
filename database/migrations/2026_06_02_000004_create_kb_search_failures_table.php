<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.8/W4 — content-gap analytics: the questions the KB could NOT answer.
 *
 * Every refused chat turn (the deterministic grounding gate OR the LLM
 * self-refusal sentinel — both are "we had no grounded answer") increments a
 * rollup row here, keyed on the normalized query. The admin "Content Gaps"
 * panel ranks these so editors can see what to write next and hand the query
 * to the promotion-suggest flow. `resolved_at` lets an operator dismiss a gap
 * once an article covers it.
 *
 * Tenant-aware per R30/R31. `project_key=''` means the turn carried no project
 * filter. Composite UNIQUE keeps the rollup idempotent per
 * (tenant, project, query, reason).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_search_failures', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120)->default('');
            // SHA-256 of the normalized query — bounded unique anchor.
            $table->char('query_hash', 64);
            $table->string('normalized_query', 500);
            $table->text('query_text');
            // 'no_relevant_context' | 'llm_self_refusal' | ... (refusal reason).
            $table->string('reason', 40);
            $table->unsignedInteger('occurrences')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            // Set when an operator marks the gap addressed; excluded from the
            // default ranked list.
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_key', 'query_hash', 'reason'], 'uq_kb_search_failures');
            // Admin ranking hot path: open gaps by frequency.
            $table->index(['tenant_id', 'resolved_at', 'occurrences'], 'ix_kb_search_failures_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_search_failures');
    }
};
