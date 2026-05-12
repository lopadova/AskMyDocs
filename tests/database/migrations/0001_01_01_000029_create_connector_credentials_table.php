<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connector framework: encrypted OAuth credential store.
 *
 * One row per `connector_installations.id`. Access + refresh tokens
 * are encrypted at rest via Laravel `Crypt::encryptString()` before
 * being written by
 * `Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault`.
 *
 * `tenant_id` is denormalised onto this table even though
 * `connector_installations` already carries it — the redundancy
 * lets an architecture-level R30 sweep verify cross-tenant
 * isolation without a join, and keeps
 * `OAuthCredentialVault::clearCredentials()` able to enforce tenant
 * scoping in a single WHERE clause.
 *
 * Cascade on installation delete: when an installation row is
 * removed (operator disconnect), the credential row goes with it —
 * no orphaned encrypted secrets. Defence in depth:
 * `OAuthCredentialVault::clearCredentials()` additionally deletes
 * the row explicitly + revokes upstream when the connector
 * supports it.
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
