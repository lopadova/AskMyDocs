<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v8.37 / ADR 0031 §2 — per-page review progress on a converted (OCR'd)
 * document. One row per (tenant, document, page); a page has exactly one
 * current review state, upserted, never accumulated. Cascade-deletes with
 * its document; a soft delete leaves the row in place, exactly like every
 * other tenant-aware child table of `knowledge_documents`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_document_page_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('knowledge_document_id')
                ->constrained('knowledge_documents')
                ->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('status', 16)->default('unreviewed');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'knowledge_document_id', 'page_number'],
                'uq_kb_page_reviews_tenant_doc_page',
            );
            $table->index(
                ['tenant_id', 'knowledge_document_id', 'status'],
                'idx_kb_page_reviews_tenant_doc_status',
            );
            // Copilot PR #494 — every index above LEADS with tenant_id, but
            // the FK cascade fired by a hard document delete probes by
            // knowledge_document_id ALONE (Postgres does not auto-index FK
            // columns). A document-id-leading index keeps that cascade a
            // fast index scan instead of a full-table scan as this table
            // grows.
            $table->index('knowledge_document_id', 'idx_kb_page_reviews_document_id');
        });

        // Copilot PR #494 — page_number is 1-based (ADR 0031 §2); a bare
        // unsignedInteger still admits 0. KbReviewService::setPageReviewStatus()
        // guards this at the application layer (the single write path every
        // surface funnels through, R44) — this CHECK is defense-in-depth on
        // Postgres, the production driver. SQLite cannot ALTER TABLE ADD a
        // CHECK constraint after CREATE TABLE (same limitation the pgvector
        // fallback above this migration's sibling already documents), so the
        // SQLite test mirror relies on the application-layer guard alone.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE kb_document_page_reviews ADD CONSTRAINT chk_kb_page_reviews_page_number_positive CHECK (page_number >= 1)',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_document_page_reviews');
    }
};
