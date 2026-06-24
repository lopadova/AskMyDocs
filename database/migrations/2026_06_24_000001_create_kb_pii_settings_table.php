<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.23 (Ciclo 4) — per-(tenant, project) PII ingestion policy.
 *
 * The PII posture at ingestion time (whether to redact, and with which
 * strategy) defaults from `config('kb.pii_redactor.*')`. This table lets an
 * operator override it for a specific project — or, with `project_key='*'`,
 * for the whole tenant — so the helpdesk scenario ("project `acme-support`
 * tokenises; the rest masks") is configurable without a deploy.
 *
 * Every override column is NULLABLE: a null field INHERITS the next level up
 * (exact project → tenant-wide `*` → config default), so an override can flip
 * one knob without restating the others. Resolution lives in
 * {@see \App\Services\Kb\Pii\KbPiiPolicyResolver}.
 *
 * Tenant-aware per R30/R31; composite unique on (tenant_id, project_key) per
 * R28 — two tenants legitimately share `project_key='support'`. The table is
 * deliberately narrow now (the two knobs wired into the inline ingestion path)
 * and grows additively (R27) as later Ciclo 4 PRs add detokenizable-entity and
 * detector policy columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_pii_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            // The project this override applies to. '*' = tenant-wide default.
            $table->string('project_key', 120);
            // NULL = inherit config('kb.pii_redactor.redact_inline_ingest').
            // When effective-true (and the master PII flags are on) the inline
            // DocumentIngestor path tokenises/masks chunk text before embedding.
            $table->boolean('redact_enabled')->nullable();
            // NULL = inherit config('kb.pii_redactor.ingest_strategy').
            // 'mask' (one-way) or 'tokenise' (reversible per-tenant vault).
            $table->string('strategy', 20)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_key'], 'uq_kb_pii_settings_tenant_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_pii_settings');
    }
};
