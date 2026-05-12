<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7/W2 — TEST mirror of:
 *   database/migrations/2026_05_12_000003_create_workflows_table.php
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index('idx_workflows_tenant_id');
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('type', 20);
            $table->text('prompt_md');
            $table->json('columns_config')->nullable();
            $table->string('practice', 30)->default('generic');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id'], 'idx_workflows_tenant_user');
            $table->index(['tenant_id', 'type'], 'idx_workflows_tenant_type');
            $table->index(['tenant_id', 'is_system'], 'idx_workflows_tenant_system');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
