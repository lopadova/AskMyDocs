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
            $table->string('source_type', 64)->default('markdown');
            $table->string('title');
            $table->string('source_path');
            $table->string('mime_type', 128)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('access_scope', 64)->nullable();
            $table->string('status', 32)->default('indexed');
            $table->string('document_hash', 64)->nullable();
            $table->string('version_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
