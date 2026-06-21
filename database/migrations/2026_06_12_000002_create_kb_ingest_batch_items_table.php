<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `kb_ingest_batch_items` — one row per file inside an upload batch.
 *
 * Carries the original filename, the machine-generated staging path, the
 * computed final destination path (KbPath::normalize'd, R1), and the per-file
 * status. `knowledge_document_id` is filled by the `KnowledgeDocument::created`
 * observer once ingest succeeds (no hard FK by design — the doc may later be
 * pruned, mirroring kb_canonical_audit's forensic-survives-delete stance).
 *
 * Tenant-aware (R30/R31): `tenant_id` denormalised here for direct scoping;
 * composite indexes start with `tenant_id`. Cascade-deletes with the batch.
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
            // Relative path on the kb-staging disk (machine-generated uuids).
            $table->string('staging_path', 700);
            // Relative path on the kb disk after commit (prefix + sub_path + name).
            $table->string('destination_path', 700);
            $table->string('mime_type', 200);
            $table->string('source_type', 40);
            $table->unsignedBigInteger('size_bytes');
            // staged | moving | queued | processing | succeeded | failed
            $table->string('status', 20)->default('staged')->index();
            // Decision 4 (mixed): canonical files are allowed but flagged with a
            // non-blocking warning ("won't be in git").
            $table->boolean('is_canonical')->default(false);
            $table->text('canonical_warning')->nullable();
            $table->text('error')->nullable();
            // No FK on purpose — the document can be hard-deleted later.
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
