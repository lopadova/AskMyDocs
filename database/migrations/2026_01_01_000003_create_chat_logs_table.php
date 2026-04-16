<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_logs', function (Blueprint $table) {
            $table->id();

            // Session & user
            $table->uuid('session_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Question / Answer
            $table->text('question');
            $table->text('answer');
            $table->string('project_key', 120)->nullable()->index();

            // AI provider details
            $table->string('ai_provider', 64)->index();
            $table->string('ai_model', 128)->index();

            // RAG context
            $table->unsignedSmallInteger('chunks_count')->default(0);
            $table->json('sources')->nullable();

            // Token usage
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();

            // Performance
            $table->unsignedInteger('latency_ms');

            // Client info
            $table->string('client_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // Extensible metadata
            $table->json('extra')->nullable();

            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_logs');
    }
};
