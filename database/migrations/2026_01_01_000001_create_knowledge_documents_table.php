<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->string('project_key', 120)->index();
            $table->string('source_type', 32)->index(); // markdown|pdf|docx
            $table->string('title');
            $table->string('source_path');
            $table->string('mime_type')->nullable();
            $table->string('language', 16)->default('it')->index();
            $table->string('access_scope', 64)->default('internal')->index();
            $table->string('status', 32)->default('active')->index(); // active|archived|draft
            $table->string('document_hash', 64)->index();
            $table->string('version_hash', 64)->index();
            $table->json('metadata')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_key', 'source_path', 'version_hash'], 'uq_kb_doc_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
