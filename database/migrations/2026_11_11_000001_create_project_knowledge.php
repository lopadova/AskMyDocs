<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_specification_scopes', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 128);
            $t->string('project_key', 128);
            $t->unsignedBigInteger('active_revision_id')->nullable();
            $t->boolean('enabled')->default(false);
            $t->timestamps();
            $t->unique(['tenant_id', 'project_key'], 'knowledge_scope_unique');
        });
        Schema::create('knowledge_specifications', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 128);
            $t->string('project_key', 128);
            $t->text('description');
            $t->json('blocks');
            $t->string('created_by')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'project_key']);
        });
        Schema::create('knowledge_extractions', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 128);
            $t->string('project_key', 128);
            $t->foreignId('knowledge_document_id')->constrained()->cascadeOnDelete();
            $t->string('source_version', 64);
            $t->string('specification_hash', 64);
            $t->string('extractor_version', 64);
            $t->string('idempotency_key', 64)->unique();
            $t->json('contract');
            $t->string('status', 32)->default('pending');
            $t->unsignedInteger('total_sections')->default(0);
            $t->unsignedInteger('processed_sections')->default(0);
            $t->unsignedInteger('abstained_sections')->default(0);
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'project_key', 'status'], 'knowledge_run_scope');
        });
        Schema::create('knowledge_sections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('extraction_id')->constrained('knowledge_extractions')->cascadeOnDelete();
            $t->unsignedInteger('ordinal');
            $t->unsignedBigInteger('chunk_id')->nullable();
            $t->string('heading', 1000)->nullable();
            $t->unsignedInteger('offset')->default(0);
            $t->longText('text');
            $t->string('text_hash', 64);
            $t->string('status', 32)->default('pending');
            $t->json('result')->nullable();
            $t->json('decisions')->nullable();
            $t->json('usage')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->unique(['extraction_id', 'ordinal']);
        });
        Schema::create('knowledge_entities', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 128);
            $t->string('project_key', 128);
            $t->string('type', 64);
            $t->string('namespace', 64);
            $t->string('identifier', 255);
            $t->string('identity_hash', 64)->unique();
            $t->timestamps();
            $t->index(['tenant_id', 'project_key', 'type'], 'knowledge_entity_scope');
        });
        Schema::create('knowledge_observations', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 128);
            $t->string('project_key', 128);
            $t->foreignId('entity_id')->constrained('knowledge_entities')->cascadeOnDelete();
            $t->foreignId('extraction_id')->constrained('knowledge_extractions')->cascadeOnDelete();
            $t->foreignId('section_id')->constrained('knowledge_sections')->cascadeOnDelete();
            $t->unsignedBigInteger('target_entity_id')->nullable();
            $t->string('relation', 64)->nullable();
            $t->string('rule', 64);
            $t->string('property', 64);
            $t->text('value');
            $t->text('quote');
            $t->unsignedInteger('quote_start');
            $t->string('support', 32);
            $t->string('fingerprint', 64)->unique();
            $t->timestamp('event_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'project_key', 'entity_id', 'id'], 'knowledge_timeline');
        });
        Schema::table('kb_edges', fn (Blueprint $t) => $t->string('edge_category', 24)->default('document')->index());
        Schema::table('kb_nodes', function (Blueprint $t) {
            $t->string('node_category', 24)->default('document')->index();
        });
    }

    public function down(): void
    {
        // Remove projections before dropping category columns; canonical data is untouched.
        \Illuminate\Support\Facades\DB::table('kb_edges')->where('edge_category', 'entity')->delete();
        \Illuminate\Support\Facades\DB::table('kb_nodes')->where('node_category', 'entity')->delete();
        Schema::table('kb_edges', fn (Blueprint $t) => $t->dropIndex(['edge_category']));
        Schema::table('kb_edges', fn (Blueprint $t) => $t->dropColumn('edge_category'));
        Schema::table('kb_nodes', fn (Blueprint $t) => $t->dropIndex(['node_category']));
        Schema::table('kb_nodes', fn (Blueprint $t) => $t->dropColumn('node_category'));
        foreach (['knowledge_observations', 'knowledge_entities', 'knowledge_sections', 'knowledge_extractions', 'knowledge_specifications', 'knowledge_specification_scopes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
