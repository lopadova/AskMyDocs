<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7/W2 — TEST mirror of:
 *   database/migrations/2026_05_12_000005_create_hidden_workflows_table.php
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hidden_workflows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index('idx_hidden_workflows_tenant_id');
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('workflow_id')
                ->constrained('workflows')
                ->cascadeOnDelete();
            $table->timestamp('hidden_at')->useCurrent();

            $table->unique(
                ['tenant_id', 'user_id', 'workflow_id'],
                'uq_hidden_workflows_tenant_user_workflow',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hidden_workflows');
    }
};
