<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_agent_tool_calls', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')
                ->constrained('realtime_agent_sessions')
                ->cascadeOnDelete();
            $table->string('provider_call_id')->nullable();
            $table->string('tool');
            $table->json('arguments');
            $table->unsignedBigInteger('base_revision');
            $table->unsignedBigInteger('state_revision_before')->nullable();
            $table->unsignedBigInteger('state_revision_after')->nullable();
            $table->string('authorization_status')->default('allowed');
            $table->string('confirmation_status')->default('not_required');
            $table->string('status')->index();
            $table->json('result')->nullable();
            $table->json('error')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_agent_tool_calls');
    }
};
