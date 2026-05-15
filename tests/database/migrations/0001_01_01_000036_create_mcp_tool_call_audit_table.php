<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Test-side mirror of the post-v7.0/W1.B schema: pre-existing
        // host columns + the package-contract columns (input_hash,
        // actor, mcp_server_name, error_excerpt) baked into the same
        // CREATE so SQLite (no ALTER COLUMN NULL) can satisfy the
        // production ALTER migration as a no-op.
        Schema::create('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('actor', 100)->nullable();
            $table->foreignId('mcp_server_id')->constrained('mcp_servers')->cascadeOnDelete();
            $table->string('mcp_server_name', 150)->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('tool_name', 100);
            $table->json('input_json_redacted')->nullable();
            $table->char('input_hash', 64)->nullable();
            $table->string('result_hash', 64)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('status', 32)->default('ok');
            $table->json('error_json')->nullable();
            $table->string('error_excerpt', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'idx_mcp_tool_call_audit_tenant_created_at');
            $table->index(
                ['tenant_id', 'mcp_server_id', 'tool_name'],
                'idx_mcp_tool_call_audit_tenant_server_tool',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_call_audit');
    }
};

