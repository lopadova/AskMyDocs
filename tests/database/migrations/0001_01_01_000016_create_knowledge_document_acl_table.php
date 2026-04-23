<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_document_acl', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('knowledge_document_id')
                ->constrained('knowledge_documents')
                ->cascadeOnDelete();
            // One of: 'user' | 'role' | 'team'. Kept as varchar with an
            // app-level constraint (KnowledgeDocumentAcl::SUBJECT_*) rather
            // than a DB enum for portability.
            $table->string('subject_type', 32);
            // user_id (bigint-as-string), role name, or team slug — all coerced
            // to string so the same column works across subject types.
            $table->string('subject_id', 120);
            // view | edit | delete | promote
            $table->string('permission', 32);
            // allow | deny — deny wins when both exist for the same subject.
            $table->string('effect', 8)->default('allow');
            $table->timestamps();

            $table->index(
                ['knowledge_document_id', 'subject_type', 'subject_id'],
                'ix_kb_doc_acl_doc_subject'
            );
            $table->index(
                ['subject_type', 'subject_id'],
                'ix_kb_doc_acl_subject'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_document_acl');
    }
};
