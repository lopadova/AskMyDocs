<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_chunk_feedback', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('knowledge_chunk_id')
                ->constrained('knowledge_chunks')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('signal', 32);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'user_id', 'knowledge_chunk_id'],
                'uq_kb_chunk_feedback_tenant_user_chunk',
            );
            $table->index(
                ['tenant_id', 'knowledge_chunk_id', 'signal'],
                'idx_kb_chunk_feedback_tenant_chunk_signal',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_chunk_feedback');
    }
};

