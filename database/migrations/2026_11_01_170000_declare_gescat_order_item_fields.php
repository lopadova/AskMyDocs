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
        foreach (DB::table('api_routes')->where('tenant_id', 'autry')->whereIn('slug', ['gescat_cerca_ordini', 'gescat_dettaglio_ordine'])->where('http_method', 'GET')->get() as $route) {
            if (strtolower((string) parse_url($route->url, PHP_URL_HOST)) !== 'www.autry-usa.com'
                || rtrim((string) parse_url($route->url, PHP_URL_PATH), '/') !== '/api/admin/ws/ws_orders') continue;
            $schema = json_decode($route->output_schema ?? '', true);
            $original = $schema;
            $path = 'properties.order.items.properties.articoli.items.properties.articolo';
            $articles = data_get($schema, $path);
            if (! is_array($articles['items']['properties'] ?? null)
                || (array_key_exists('x-field', $articles) && ! in_array('items', (array) $articles['x-field'], true))) continue;
            $articles['x-field'] ??= 'items';
            $properties = &$articles['items']['properties'];
            foreach (['art_pcode' => 'reference', 'DescArticolo' => 'description', 'variante' => 'variant', 'art_qta' => 'quantity'] as $field => $role) {
                if (! is_array($properties[$field] ?? null) || array_key_exists('x-field', $properties[$field])
                    || array_filter($properties, fn ($property) => is_array($property) && in_array($role, (array) ($property['x-field'] ?? []), true))) continue;
                $properties[$field]['x-field'] = $role;
            }
            unset($properties);
            data_set($schema, $path, $articles);
            if ($schema === $original) continue;
            DB::table('api_routes')->where('id', $route->id)->where('tenant_id', 'autry')->update([
                'output_schema' => json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve explicit business declarations on code rollback.
    }
};
