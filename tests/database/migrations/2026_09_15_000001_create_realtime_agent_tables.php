<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_agent_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('agent_key');
            $table->nullableMorphs('owner');
            $table->string('provider');
            $table->string('provider_session_id')->nullable();
            $table->string('status')->index();
            $table->json('state');
            $table->json('definition');
            $table->unsignedBigInteger('state_revision');
            $table->unsignedBigInteger('event_sequence')->default(0);
            $table->unsignedBigInteger('message_sequence')->default(0);
            $table->unsignedBigInteger('usage_sequence')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('realtime_agent_events', function (Blueprint $table): void {
            $table->string('id', 30)->primary();
            $table->foreignUlid('session_id')->constrained('realtime_agent_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('seq');
            $table->string('type')->index();
            $table->string('source');
            $table->json('payload');
            $table->unsignedBigInteger('state_revision');
            $table->string('provider_event_id')->nullable();
            $table->timestamp('created_at');
            $table->unique(['session_id', 'seq']);
        });

        Schema::create('realtime_agent_tool_calls', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')->constrained('realtime_agent_sessions')->cascadeOnDelete();
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

        Schema::create('realtime_agent_provider_tools', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider');
            $table->string('schema_hash', 64);
            $table->string('provider_tool_id');
            $table->json('definition');
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['provider', 'schema_hash']);
        });

        Schema::create('realtime_agent_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')->constrained('realtime_agent_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('seq');
            $table->string('provider');
            $table->string('provider_event_id')->nullable();
            $table->string('role');
            $table->string('direction');
            $table->string('modality');
            $table->string('status')->default('completed');
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['session_id', 'seq']);
            $table->unique(['session_id', 'idempotency_key']);
            $table->index(['session_id', 'occurred_at']);
        });

        Schema::create('realtime_agent_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')->constrained('realtime_agent_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('seq');
            $table->string('provider');
            $table->string('provider_event_id')->nullable();
            $table->string('kind');
            $table->string('model')->nullable();
            $table->json('units');
            $table->json('raw');
            $table->json('pricing')->nullable();
            $table->decimal('amount', 18, 8)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('estimated');
            $table->string('idempotency_key', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['session_id', 'seq']);
            $table->unique(['session_id', 'idempotency_key']);
            $table->index(['session_id', 'occurred_at']);
            $table->index(['provider', 'model']);
        });

        Schema::create('realtime_agent_session_links', function (Blueprint $table): void {
            $table->ulid('session_id')->primary();
            $table->foreign('session_id')->references('id')->on('realtime_agent_sessions')->cascadeOnDelete();
            $table->string('tenant_id', 50)->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->json('filters')->nullable();
            $table->json('live_sources')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(
                ['tenant_id', 'user_id', 'conversation_id'],
                'idx_realtime_links_tenant_user_conversation',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_agent_session_links');
        Schema::dropIfExists('realtime_agent_usage');
        Schema::dropIfExists('realtime_agent_messages');
        Schema::dropIfExists('realtime_agent_provider_tools');
        Schema::dropIfExists('realtime_agent_tool_calls');
        Schema::dropIfExists('realtime_agent_events');
        Schema::dropIfExists('realtime_agent_sessions');
    }
};
