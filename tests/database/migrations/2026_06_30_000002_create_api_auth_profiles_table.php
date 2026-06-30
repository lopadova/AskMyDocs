<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API authentication profile (spec §4.3 / §10).
 *
 * Holds the credentials/header set used to authenticate a connector (or an
 * individual route that overrides it) against an external domain.
 *
 * `credentials` is encrypted at rest via the model's `encrypted:array` cast and
 * is `$hidden` from serialization — the secret values never reach the LLM, the
 * API responses, or the logs. `type` is stored as varchar (the `enum` Blueprint
 * type is fragile across drivers); the model validates the allowed values:
 * none | api_key | bearer | basic | custom | oauth2_cc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_auth_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('api_connector_id')
                ->constrained('api_connectors')
                ->cascadeOnDelete();
            $table->string('type', 24)->default('none');
            // Encrypted JSON blob (api_key / token / username+password / headers /
            // client_id+client_secret). Encryption happens in the model cast.
            $table->text('credentials')->nullable();
            // Non-secret config, e.g. the OAuth2 token endpoint + header name.
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'api_connector_id'], 'idx_api_auth_profiles_tenant_connector');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_auth_profiles');
    }
};
