<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * List → Detail relation (spec Obj 3). Links a LIST route to a DETAIL route and
 * carries the field map that binds the list item's fields to the detail route's
 * parameters — so the LLM (and the admin drill-test) can go from a collection
 * item to its single-resource detail.
 *
 * Cardinality is many-to-many through this join: one list → many details, one
 * detail ← many lists. `field_map` is an ordered JSON list of
 * `{from: <dot-path in a single list item>, to_param: <detail param name>,
 * to_location?: <path|query|header|body>}`.
 *
 * The FK columns reference `id` only (codebase pattern); cross-tenant isolation
 * is enforced at the application layer (R30 forTenant scoping in the service),
 * not by the FK. Deleting either endpoint route cascades the relation away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_route_relations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('api_connector_id')
                ->constrained('api_connectors')
                ->cascadeOnDelete();
            $table->foreignId('list_route_id')
                ->constrained('api_routes')
                ->cascadeOnDelete();
            $table->foreignId('detail_route_id')
                ->constrained('api_routes')
                ->cascadeOnDelete();
            $table->string('name', 128)->nullable();
            $table->text('description')->nullable(); // documents the chain to the LLM (Fase 3)
            $table->json('field_map'); // ordered [{from, to_param, to_location?}]
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'list_route_id', 'detail_route_id'],
                'uq_api_route_relations_pair'
            );
            $table->index(['tenant_id', 'api_connector_id'], 'idx_api_route_relations_tenant_connector');
            $table->index(['tenant_id', 'list_route_id'], 'idx_api_route_relations_tenant_list');
            $table->index(['tenant_id', 'detail_route_id'], 'idx_api_route_relations_tenant_detail');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_route_relations');
    }
};
