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
            // v7.0/W6.3 — user_id is NULLABLE: the package writer
            // only knows the string `actor`; host code still fills
            // user_id when available.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('actor', 100)->nullable();
            $table->foreignId('mcp_server_id')->constrained('mcp_servers')->cascadeOnDelete();
            // v7.0/W6.3 — denormalised server name for audit
            // reports; populated by the package writer alongside
            // the FK so reports don't need to join `mcp_servers`.
            $table->string('mcp_server_name', 100)->nullable();
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
            // v7.0/W6.3 — nullable so the package writer can persist
            // failed-call audit rows where there's no result to hash.
            $table->string('result_hash', 64)->nullable();
            $table->unsignedInteger('duration_ms');
            // v7.0/W6.3 — `status` widens from ENUM to string(32)
            // so the package can emit `transport_error` and any
            // future package-defined value without an enum migration.
            $table->string('status', 32)->default('ok');
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

