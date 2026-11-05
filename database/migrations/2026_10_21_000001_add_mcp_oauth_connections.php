<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mcp_project_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('tenant_id', 64)->nullable();
            $table->string('project_key', 120)->nullable();
            $table->timestamps();
        });
        foreach (['oauth_authorization_codes', 'oauth_access_tokens'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignUuid('mcp_connection_id')->nullable()->constrained('mcp_project_connections')->cascadeOnDelete();
            });
        }
        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('oauth_access_token_id')->constrained('oauth_access_tokens')->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('mcp_document_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mcp_connection_id')->constrained('mcp_project_connections')->cascadeOnDelete();
            $table->foreignId('oauth_access_token_id')->constrained('oauth_access_tokens')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_id', 64);
            $table->string('project_key', 120);
            $table->char('fingerprint', 64);
            $table->foreignUuid('batch_id')->nullable()->constrained('kb_ingest_batches')->restrictOnDelete();
            $table->json('manifest');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['mcp_connection_id', 'fingerprint'], 'mcp_import_fingerprint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_document_imports');
        Schema::dropIfExists('oauth_refresh_tokens');
        foreach (['oauth_access_tokens', 'oauth_authorization_codes'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('mcp_connection_id'));
        }
        Schema::dropIfExists('mcp_project_connections');
    }
};
