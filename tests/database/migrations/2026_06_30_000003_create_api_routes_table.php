<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API route (Rotta) — one HTTP endpoint = one LLM Tool (spec §4 / §6 / §10).
 *
 * Carries both the operator-facing config (method, url, operational overrides)
 * and the generated artifacts (input_schema exposed to the LLM, output_schema,
 * param_mapping binding, cached tool_definition, output_transform).
 *
 * `project_key` is DENORMALISED from the parent connector (NOT NULL, '' when the
 * connector has no project) so the slug-uniqueness rule "tool name unique per
 * KB" (spec) can be enforced at the DB level without a NULL-distinct gap:
 * unique `(tenant_id, project_key, slug)`. The service keeps it in sync and
 * rejects a connector project_key change that would orphan routes (R28).
 *
 * Enums stored as varchar (driver portability), validated in the model:
 *   http_method ∈ GET|POST|PUT|PATCH|DELETE
 *   mode        ∈ tool|ingest|both   (Fase 1 = tool; ingest/both reserved)
 *   status      ∈ draft|tested|active|disabled
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_routes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('api_connector_id')
                ->constrained('api_connectors')
                ->cascadeOnDelete();
            $table->string('project_key', 100)->default('');
            $table->string('name', 128);
            $table->string('slug', 96); // the tool name exposed to the LLM
            $table->text('description')->nullable();
            $table->string('http_method', 8)->default('GET');
            $table->string('url', 2048);
            // Optional override of the connector's default auth profile.
            $table->unsignedBigInteger('auth_profile_id')->nullable();
            $table->json('input_schema')->nullable();    // JSON schema (llm params only)
            $table->json('output_schema')->nullable();   // inferred response structure
            $table->json('param_mapping')->nullable();   // location/source/value/ref per param
            $table->json('tool_definition')->nullable(); // {name,description,input_schema} cache
            $table->json('output_transform')->nullable();// field selection / JSONPath / limits
            $table->string('mode', 8)->default('tool');
            $table->string('status', 12)->default('draft');
            $table->unsignedInteger('timeout_ms')->nullable();
            $table->unsignedInteger('cache_ttl_s')->nullable();
            $table->unsignedInteger('rate_limit')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_status', 32)->nullable();
            $table->json('last_test_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'project_key', 'slug'],
                'uq_api_routes_tenant_project_slug'
            );
            $table->index(['tenant_id', 'status', 'mode'], 'idx_api_routes_tenant_status_mode');
            $table->index(['tenant_id', 'api_connector_id'], 'idx_api_routes_tenant_connector');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_routes');
    }
};
