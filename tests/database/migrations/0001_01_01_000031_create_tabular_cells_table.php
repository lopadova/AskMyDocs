<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7/W1 — TEST mirror of:
 *   database/migrations/2026_05_12_000002_create_tabular_cells_table.php
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tabular_cells', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default');
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
