<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API connector — the container entity (spec §10).
 *
 * Groups one or more Rotte (api_routes) and shared configuration: an optional
 * base URL default, shared headers and a default auth profile. Each connector
 * belongs to a tenant and optionally to a KB project.
 *
 * Tenant isolation: composite unique on `(tenant_id, project_key, name)` lets
 * two tenants — or two projects of one tenant — reuse the same connector name.
 * R30 query-side scoping comes from the `BelongsToTenant` trait on the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_connectors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            // KB project binding (nullable; '' is normalised at the route level).
            $table->string('project_key', 100)->nullable();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->string('base_url', 2048)->nullable();
            // No DB FK on default_auth_profile_id: api_auth_profiles also FK back
            // to api_connectors (circular). The application enforces the link.
            $table->unsignedBigInteger('default_auth_profile_id')->nullable();
            $table->json('headers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'project_key', 'name'],
                'uq_api_connectors_tenant_project_name'
            );
            $table->index(['tenant_id', 'is_active'], 'idx_api_connectors_tenant_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_connectors');
    }
};
