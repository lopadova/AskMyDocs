<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API route parameter (spec §4.2 / §10 normalised option).
 *
 * The two axes of every parameter:
 *   location ∈ path | query | header | body   — WHERE it goes in the request
 *   source   ∈ llm  | fixed | secret          — WHO decides the value
 *
 * Only `source = llm` parameters enter the tool's input_schema exposed to the
 * model; `fixed` carry a constant `value`; `secret` reference an auth profile
 * field via `secret_ref` and are NEVER exposed. `type` mirrors JSON Schema
 * scalar/compound types. Enums stored as varchar, validated in the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_route_parameters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('api_route_id')
                ->constrained('api_routes')
                ->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('location', 8);  // path|query|header|body
            $table->string('source', 8);    // llm|fixed|secret
            $table->string('type', 12)->default('string');
            $table->boolean('required')->default(false);
            $table->text('value')->nullable();        // for source=fixed
            $table->string('secret_ref', 128)->nullable(); // for source=secret
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'api_route_id'], 'idx_api_route_params_tenant_route');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_route_parameters');
    }
};
