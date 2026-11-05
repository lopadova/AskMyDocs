<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_routes')) return;
        foreach (DB::table('api_routes')->whereIn('slug', ['gescat_cerca_ordini', 'gescat_dettaglio_ordine'])->where('http_method', 'GET')->get() as $route) {
            if (strtolower((string) parse_url($route->url, PHP_URL_HOST)) !== 'www.autry-usa.com'
                || rtrim((string) parse_url($route->url, PHP_URL_PATH), '/') !== '/api/admin/ws/ws_orders') continue;
            $schema = json_decode($route->output_schema ?? '', true);
            if (! is_array($schema['properties']['order']['items']['properties'] ?? null)) continue;
            $properties = &$schema['properties']['order']['items']['properties'];
            foreach (['ord_codiceweb' => 'reference', 'stato_ordine' => 'status', 'ord_totale_finale' => 'total',
                'valute_codice' => 'currency', 'ord_data' => 'date'] as $field => $role) {
                // Preserve explicit operator mappings, including another field
                // already occupying the role. No raw result or credential changes.
                if (! is_array($properties[$field] ?? null) || array_key_exists('x-field', $properties[$field])
                    || array_filter($properties, fn ($property) => is_array($property) && in_array($role, (array) ($property['x-field'] ?? []), true))) continue;
                $properties[$field]['x-field'] = $role;
            }
            unset($properties);
            DB::table('api_routes')->where('id', $route->id)->where('tenant_id', $route->tenant_id)->update([
                'output_schema' => json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve verified output declarations on code rollback.
    }
};
