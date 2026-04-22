<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SQLite-compatible mirror of
// database/migrations/2026_04_22_000001_add_canonical_columns_to_knowledge_documents.php
// All column types (varchar, bool, smallint, json) work natively on SQLite.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->string('doc_id', 128)->nullable()->after('id')->index();
            $table->string('slug', 255)->nullable()->after('doc_id')->index();
            $table->string('canonical_type', 64)->nullable()->after('source_type')->index();
            $table->string('canonical_status', 64)->nullable()->after('canonical_type')->index();
            $table->boolean('is_canonical')->default(false)->after('canonical_status')->index();
            $table->unsignedSmallInteger('retrieval_priority')->default(50)->after('is_canonical');
            $table->boolean('source_of_truth')->default(true)->after('retrieval_priority');
            $table->json('frontmatter_json')->nullable()->after('metadata');

            $table->unique(['project_key', 'doc_id'], 'uq_kb_doc_doc_id');
            $table->unique(['project_key', 'slug'], 'uq_kb_doc_slug');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropUnique('uq_kb_doc_doc_id');
            $table->dropUnique('uq_kb_doc_slug');
            $table->dropIndex('knowledge_documents_doc_id_index');
            $table->dropIndex('knowledge_documents_slug_index');
            $table->dropIndex('knowledge_documents_canonical_type_index');
            $table->dropIndex('knowledge_documents_canonical_status_index');
            $table->dropIndex('knowledge_documents_is_canonical_index');
            $table->dropColumn([
                'doc_id',
                'slug',
                'canonical_type',
                'canonical_status',
                'is_canonical',
                'retrieval_priority',
                'source_of_truth',
                'frontmatter_json',
            ]);
        });
    }
};
