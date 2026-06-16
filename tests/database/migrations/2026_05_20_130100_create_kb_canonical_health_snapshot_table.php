<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_canonical_health_snapshot', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('knowledge_document_id')
                ->constrained('knowledge_documents')
                ->cascadeOnDelete();
            $table->string('project_key', 100);
            $table->string('doc_slug', 255)->nullable();
            $table->unsignedTinyInteger('health_score');
            $table->json('factors')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'knowledge_document_id'],
                'uq_kb_health_snapshot_tenant_doc',
            );
            $table->index(
                ['tenant_id', 'project_key', 'health_score'],
                'idx_kb_health_snapshot_tenant_project_score',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_canonical_health_snapshot');
    }
};

