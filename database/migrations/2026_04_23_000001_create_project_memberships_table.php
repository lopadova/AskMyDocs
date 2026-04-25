<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_memberships', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('project_key', 120)->index();
            // member / admin / owner
            $table->string('role', 64)->default('member');
            // JSON map; keys: folder_globs (array of shell globs against
            // knowledge_documents.source_path) and tags (array of kb_tags.slug).
            // NULL = no scope restriction within the project.
            $table->json('scope_allowlist')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'project_key'], 'uq_project_memberships_user_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_memberships');
    }
};
