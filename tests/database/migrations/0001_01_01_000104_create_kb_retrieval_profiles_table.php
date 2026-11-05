<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SQLite mirror of the production retrieval-profile migration. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_retrieval_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->index();
            $table->string('project_key', 120);
            $table->text('company_context');
            $table->json('glossary')->nullable();
            $table->json('relevant_entities')->nullable();
            $table->json('expected_facts')->nullable();
            $table->json('preferred_source_types')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_key'], 'uq_kb_retrieval_profile_tenant_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_retrieval_profiles');
    }
};
