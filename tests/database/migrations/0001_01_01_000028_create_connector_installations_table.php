<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test mirror of database/migrations/2026_05_15_000001_create_connector_installations_table.php.
 * SQLite-compatible (no pgvector / no enum). The application migration
 * is intentionally enum-free, so this mirror is a literal copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_installations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('connector_name', 64);
            $table->json('config_json')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('last_sync_at')->nullable();
            $table->json('error_json')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'connector_name'],
                'uq_connector_installations_tenant_name'
            );
            $table->index(['tenant_id', 'status'], 'idx_connector_installations_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_installations');
    }
};
