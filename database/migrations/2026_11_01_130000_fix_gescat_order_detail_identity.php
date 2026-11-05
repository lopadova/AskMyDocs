<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('api_routes', 'identity_fields')) return;

        foreach (DB::table('api_routes')->where('slug', 'gescat_dettaglio_ordine')->where('http_method', 'GET')->get() as $route) {
            // Repair only the verified Autry order service and its empty legacy
            // declaration. Never replace another endpoint's manual identities.
            if (strtolower((string) parse_url($route->url, PHP_URL_HOST)) !== 'www.autry-usa.com'
                || rtrim((string) parse_url($route->url, PHP_URL_PATH), '/') !== '/api/admin/ws/ws_orders'
                || ! in_array(json_decode($route->identity_fields ?? 'null', true), [null, []], true)) continue;

            DB::table('api_routes')->where('id', $route->id)->where('tenant_id', $route->tenant_id)->update([
                'identity_fields' => json_encode(['ord_codiceweb', 'ord_ID'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Data repair: code rollback must not discard the verified contract.
    }
};
