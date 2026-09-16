<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_document_page_reviews');
    }
};
