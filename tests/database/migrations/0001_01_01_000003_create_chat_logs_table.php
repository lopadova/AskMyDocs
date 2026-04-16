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
            $table->uuid('session_id')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('question');
            $table->text('answer');
            $table->string('project_key', 120)->nullable()->index();
            $table->string('ai_provider', 64)->index();
            $table->string('ai_model', 128)->index();
            $table->unsignedSmallInteger('chunks_count')->default(0);
            $table->json('sources')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('client_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('extra')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_logs');
    }
};
