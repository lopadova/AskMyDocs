<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SQLite-compatible mirror of
// database/migrations/2026_06_13_000001_add_autowiki_columns_to_knowledge_documents.php
// Runs after the canonical-columns mirror (000009) which adds `is_canonical`.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->string('generation_source', 16)->default('human')->after('is_canonical')->index();
            $table->string('markdown_path', 1024)->nullable()->after('source_path');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropIndex('knowledge_documents_generation_source_index');
            $table->dropColumn(['generation_source', 'markdown_path']);
        });
    }
};
