<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->string('project_key', 120)->index();
            $table->unsignedInteger('chunk_order')->index();
            $table->string('chunk_hash', 64)->index();
            $table->string('heading_path')->nullable()->index();
            $table->text('chunk_text');
            $table->json('metadata')->nullable();
            $table->vector('embedding', dimensions: 1536)->vectorIndex();
            $table->timestamps();

            $table->unique(['knowledge_document_id', 'chunk_hash'], 'uq_kb_chunk_doc_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
