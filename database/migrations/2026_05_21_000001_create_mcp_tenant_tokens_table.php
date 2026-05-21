<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tenant_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('label', 120);
            $table->string('token_hash', 64)->unique();
            $table->string('token_last4', 4);
            $table->json('scopes_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at', 'created_at'], 'idx_mcp_tenant_tokens_tenant_revoked_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tenant_tokens');
    }
};

