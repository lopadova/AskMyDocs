<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7/W1 — TEST mirror of:
 *   database/migrations/2026_05_12_000001_create_tabular_reviews_table.php
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tabular_reviews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default');
            $table->string('project_key', 120);
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('title', 200);
            $table->json('columns_config');
            $table->unsignedBigInteger('workflow_id')->nullable();
            $table->json('shared_with')->nullable();
            $table->string('practice', 100)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'project_key'], 'idx_tabular_reviews_tenant_project');
            $table->index(['tenant_id', 'user_id'], 'idx_tabular_reviews_tenant_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tabular_reviews');
    }
};
