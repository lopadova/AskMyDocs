<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_collection_members', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('collection_id')->constrained('kb_collections')->cascadeOnDelete();
            $table->foreignId('knowledge_document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->string('reason', 32)->default('static_match');
            $table->decimal('semantic_score', 6, 5)->nullable();
            $table->boolean('manually_excluded')->default(false);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'collection_id', 'knowledge_document_id'],
                'uq_kb_collection_members_tenant_collection_doc'
            );
            $table->index(
                ['tenant_id', 'collection_id', 'manually_excluded', 'created_at'],
                'idx_kb_collection_members_tenant_collection_excluded_created'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_collection_members');
    }
};

