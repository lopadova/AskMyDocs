<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7/W2 — `hidden_workflows` table.
 *
 * Per-user hide-from-my-list marker. A user can hide a shared or system
 * workflow without affecting other users.
 *
 * R31: tenant_id mandatory + indexed.
 * Composite unique `(tenant_id, user_id, workflow_id)` makes re-hiding
 * idempotent.
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
