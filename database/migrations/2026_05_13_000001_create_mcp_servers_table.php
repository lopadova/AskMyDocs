<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('name', 100);
            $table->enum('transport', ['stdio', 'sse', 'http'])->default('stdio');
            $table->string('endpoint', 500);
            $table->text('auth_config_encrypted')->nullable();
            $table->json('enabled_tools_json')->nullable();
            $table->enum('status', ['pending', 'active', 'disabled', 'errored'])->default('pending');
            $table->timestamp('last_handshake_at')->nullable();
            $table->json('handshake_response_json')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'uq_mcp_servers_tenant_name');
            $table->index(['tenant_id', 'status'], 'idx_mcp_servers_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_servers');
    }
};
