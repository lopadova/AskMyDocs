<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_collections', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('slug', 120);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('visibility', 16)->default('private');
            $table->json('criteria')->nullable();
            $table->text('semantic_prompt')->nullable();
            $table->json('semantic_prompt_embedding')->nullable();
            $table->decimal('threshold', 5, 4)->default(0.7500);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug'], 'uq_kb_collections_tenant_slug');
            $table->index(['tenant_id', 'visibility', 'created_at'], 'idx_kb_collections_tenant_visibility_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_collections');
    }
};

