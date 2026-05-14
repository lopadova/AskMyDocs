<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_log_provenance', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('chat_log_id')->constrained('chat_logs')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->unsignedInteger('answer_token_start');
            $table->unsignedInteger('answer_token_end');
            $table->foreignId('knowledge_chunk_id')->constrained('knowledge_chunks')->cascadeOnDelete();
            $table->string('source_path');
            $table->decimal('contribution_score', 5, 4)->default(0);
            $table->timestamps();

            $table->index(
                ['tenant_id', 'chat_log_id', 'answer_token_start'],
                'idx_chat_log_provenance_tenant_chat_token'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_log_provenance');
    }
};
