<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('actor', 100)->nullable();
            $table->foreignId('mcp_server_id')->constrained('mcp_servers')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('tool_name', 100);
            // v7.0/W6.2 — package coexistence columns (nullable). The
            // prod schema lands them via a follow-up additive
            // migration; the test schema inlines them into the
            // consolidated create so the suite doesn't need to apply
            // the additive migration separately.
            $table->char('input_hash', 64)->nullable();
            $table->json('input_json_redacted');
            $table->string('result_hash', 64);
            $table->unsignedInteger('duration_ms');
            $table->enum('status', ['ok', 'error', 'timeout', 'denied'])->default('ok');
            $table->json('error_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'idx_mcp_tool_call_audit_tenant_created_at');
            $table->index(
                ['tenant_id', 'mcp_server_id', 'tool_name'],
                'idx_mcp_tool_call_audit_tenant_server_tool',
            );
            $table->index('input_hash', 'idx_mcp_tool_call_audit_input_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_call_audit');
    }
};

