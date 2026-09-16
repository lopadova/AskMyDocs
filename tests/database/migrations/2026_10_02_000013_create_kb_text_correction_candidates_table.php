<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.37 / ADR 0031 §6-7 — an agent-proposed text correction CANDIDATE, never
 * applied content (ADR 0003's /suggest -> /candidates -> /promote pattern,
 * restated for OCR page text). `idempotency_key` is the replay/dedup
 * mechanism (sha256 of tenant.user.document.version_hash.page.old.new);
 * `status` + `consumed_at` make approval single-use under a `lockForUpdate()`
 * transaction (R21). No FK from `kb_canonical_audit` to this table, by the
 * same no-FK-by-design rule every other row that table records already
 * follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_text_correction_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('knowledge_document_id')
                ->constrained('knowledge_documents')
                ->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('version_hash', 64);
            $table->text('old_text');
            $table->text('new_text');
            $table->string('rationale', 500)->nullable();
            $table->string('idempotency_key', 64);
            $table->string('status', 16)->default('pending');
            $table->string('proposed_by', 255);
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('idempotency_key', 'uq_kb_correction_candidates_idempotency_key');
            $table->index(
                ['tenant_id', 'knowledge_document_id', 'status'],
                'idx_kb_correction_candidates_tenant_doc_status',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_text_correction_candidates');
    }
};
