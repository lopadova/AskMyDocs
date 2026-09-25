<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `kb_wiki_import_candidates` — v8.38/W4c (ADR 0032 §10/§11).
 *
 * One row per `kb:import-wiki` / `POST /api/admin/kb/imports` /
 * `KbImportWikiTool` call that resulted in a {@see \Padosoft\LaravelFlow\FlowRun}
 * being started against `Padosoft\LaravelFlow\Facades\Flow::execute(PromotionFlow::NAME, ...)`
 * — this table never stores the candidate CONTENT (that lives inside the
 * Flow run's own step results / the eventual `kb_canonical_audit` row once
 * approved); it exists purely so a REPEATED call with the same
 * `idempotency_key` re-issues a fresh approval token for the SAME paused
 * run instead of starting a second one (mirrors
 * `KbWikiExportRequestService::requestExport()`'s own idempotency-key +
 * unique-constraint-race handling, restated for the promotion saga rather
 * than for an export bundle).
 *
 * `idempotency_key` is `sha256(tenant|project_key|slug|content_hash|actor)`
 * — see {@see \App\Services\Kb\Import\KbWikiImportService::idempotencyKeyFor()}.
 * Unique PER TENANT (composite with `tenant_id`), not globally: the key
 * already includes `tenant_id`, so the composite is defense-in-depth, not
 * the correctness mechanism — same posture as `kb_wiki_export_requests`.
 *
 * Tenant-aware (R30/R31): `tenant_id` defaults to 'default', every index
 * leads with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_wiki_import_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120);
            $table->string('slug', 160);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 64);
            $table->string('content_hash', 64);
            // the PromotionFlow run's own id (Padosoft\LaravelFlow\FlowRun::$id
            // is a string, not an auto-increment int) — no FK, the flow
            // engine owns that row's lifecycle independently.
            $table->string('flow_run_id', 64);
            // cli | http | mcp — which surface proposed this candidate
            // (ADR 0032 §11 tri-surface; kept for operator diagnostics only).
            $table->string('source', 16);
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'uq_kb_wiki_import_tenant_idempotency');
            $table->index(['tenant_id', 'project_key', 'slug'], 'idx_kb_wiki_import_tenant_project_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_wiki_import_candidates');
    }
};
