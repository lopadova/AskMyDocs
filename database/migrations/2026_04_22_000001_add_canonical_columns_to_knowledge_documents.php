<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Extend knowledge_documents with the 8 canonical-layer columns. All new
 * columns are nullable / have safe defaults — back-compat with existing
 * non-canonical rows. Idempotency contract (project_key, source_path,
 * version_hash) is preserved intact; two new composite uniques on
 * (project_key, doc_id) and (project_key, slug) scope canonical identifiers
 * per tenant so the same business id / slug can legitimately appear in
 * different projects.
 */
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
            // Drop composite uniques first (they depend on the columns).
            $table->dropUnique('uq_kb_doc_doc_id');
            $table->dropUnique('uq_kb_doc_slug');
            // Drop the per-column indexes explicitly — SQLite refuses to
            // drop a column that still carries an index referencing it.
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
