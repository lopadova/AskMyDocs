<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test mirror of database/migrations/2026_05_15_000002_create_connector_credentials_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_credentials', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('connector_installation_id')
                ->constrained('connector_installations')
                ->cascadeOnDelete();
            $table->text('encrypted_access_token');
            $table->text('encrypted_refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('extra_json')->nullable();
            $table->timestamps();

            $table->unique(
                'connector_installation_id',
                'uq_connector_credentials_installation_id'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_credentials');
    }
};
