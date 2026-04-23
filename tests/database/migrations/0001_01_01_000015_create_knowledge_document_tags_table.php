<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_document_tags', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('knowledge_document_id')
                ->constrained('knowledge_documents')
                ->cascadeOnDelete();
            $table->foreignId('kb_tag_id')
                ->constrained('kb_tags')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['knowledge_document_id', 'kb_tag_id'], 'uq_kb_doc_tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_document_tags');
    }
};
