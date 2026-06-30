<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API tool-call log (spec §9 observability).
 *
 * One row per runtime tool invocation: which route, the SANITISED request
 * params (secrets redacted), the response status, a truncated/redacted excerpt
 * of the body, latency and any error. Consultable for debug + audit per
 * conversation.
 *
 * No FK to conversations/messages (those live in the host schema and may be
 * pruned independently); `conversation_id` is a soft reference. The FK to
 * `api_routes` cascades so logs vacate when a route is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tool_call_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->foreignId('api_route_id')
                ->constrained('api_routes')
                ->cascadeOnDelete();
            $table->json('request_params')->nullable();   // sanitised (no secrets)
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_excerpt')->nullable();  // truncated + redacted
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'api_route_id'], 'idx_api_tool_logs_tenant_route');
            $table->index(['tenant_id', 'conversation_id'], 'idx_api_tool_logs_tenant_conv');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tool_call_logs');
    }
};
