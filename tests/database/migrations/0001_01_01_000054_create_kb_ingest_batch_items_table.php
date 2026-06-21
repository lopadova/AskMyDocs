<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite test mirror of database/migrations/2026_06_12_000002_create_kb_ingest_batch_items_table.php.
 * No vector columns, so the body is identical to the production migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_ingest_batch_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignUuid('batch_id')->constrained('kb_ingest_batches')->cascadeOnDelete();
            $table->string('original_filename', 255);
            $table->string('staging_path', 700);
            $table->string('destination_path', 700);
            $table->string('mime_type', 200);
            $table->string('source_type', 40);
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 20)->default('staged')->index();
            $table->boolean('is_canonical')->default(false);
            $table->text('canonical_warning')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('knowledge_document_id')->nullable();
            $table->string('flow_run_id', 36)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'batch_id']);
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_ingest_batch_items');
    }
};
