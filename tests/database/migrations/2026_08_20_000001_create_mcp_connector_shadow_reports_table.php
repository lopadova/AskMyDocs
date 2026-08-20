<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connector_shadow_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50);
            $table->unsignedBigInteger('mcp_server_id')->nullable();
            $table->unsignedBigInteger('mcp_connector_connection_id')->nullable();
            $table->string('status', 32);
            $table->string('legacy_catalog_hash', 64)->nullable();
            $table->string('connector_catalog_hash', 64)->nullable();
            $table->json('summary_json');
            $table->json('blockers_json')->nullable();
            $table->json('warnings_json')->nullable();
            $table->timestamp('compared_at');
            $table->timestamps();

            $table->index(['tenant_id', 'compared_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connector_shadow_reports');
    }
};
