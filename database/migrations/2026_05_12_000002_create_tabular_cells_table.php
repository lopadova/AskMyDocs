<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7/W1 — `tabular_cells` table.
 *
 * Stores the extracted content for each (review, document, column) tuple.
 * `content` (Laravel `json` column — Postgres `json`, MySQL `json`,
 * SQLite `text`) carries `{summary, flag, reasoning, citations[]}`.
 * `status` follows the pending → generating → ready | failed state machine.
 *
 * R31: tenant_id mandatory, indexed.
 * Composite unique `(tenant_id, review_id, document_id, column_index)`
 * prevents duplicate cells under a single tenant.
 *
 * FK cascade on review_id + document_id: dropping a review wipes its
 * grid, dropping a document wipes its row across every review.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tabular_cells', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index('idx_tabular_cells_tenant_id');
            $table->foreignId('review_id')
                ->constrained('tabular_reviews')
                ->cascadeOnDelete();
            $table->foreignId('document_id')
                ->constrained('knowledge_documents')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('column_index');
            $table->json('content')->nullable();
            $table->string('status', 15)->default('pending');
            $table->string('flag', 10)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'review_id', 'document_id', 'column_index'],
                'uq_tabular_cells_tenant_review_doc_col',
            );
            $table->index(['tenant_id', 'review_id'], 'idx_tabular_cells_tenant_review');
            $table->index('status', 'idx_tabular_cells_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tabular_cells');
    }
};
