<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7/W2 — `workflow_shares` table.
 *
 * Pivot binding a workflow to the email addresses it's shared with.
 * Email-based (not user_id-based) so workflow owners can share with
 * addresses that may not yet exist as User rows.
 *
 * Composite unique `(workflow_id, shared_with_email)` makes re-sharing
 * idempotent.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('workflow_shares', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('workflow_id')
                ->constrained('workflows')
                ->cascadeOnDelete();
            $table->foreignId('shared_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('shared_with_email', 190);
            $table->boolean('allow_edit')->default(false);
            $table->timestamps();

            $table->unique(
                ['workflow_id', 'shared_with_email'],
                'uq_workflow_shares_workflow_email',
            );
            $table->index('shared_with_email', 'idx_workflow_shares_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_shares');
    }
};
