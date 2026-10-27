<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DESCRIPTION = 'Ordinamento GESCAT: data = ordini più recenti per data di creazione decrescente; num_fattura = progressivo crescente. Per gli ultimi N ordini usa orderBy=data e limit=N. Valori ammessi: data, num_fattura.';

    public function up(): void
    {
        if (! Schema::hasTable('api_routes')) return;
        foreach (DB::table('api_routes')->where('slug', 'gescat_cerca_ordini')->where('http_method', 'GET')->get() as $route) {
            // Repair the verified Autry service only, preserving tenant scope,
            // credentials, activation, field projection and every other argument.
            if (strtolower((string) parse_url($route->url, PHP_URL_HOST)) !== 'www.autry-usa.com'
                || rtrim((string) parse_url($route->url, PHP_URL_PATH), '/') !== '/api/admin/ws/ws_orders') continue;
            $schema = json_decode($route->input_schema ?? '', true);
            $definition = json_decode($route->tool_definition ?? '', true);
            $updated = [];
            if (is_array($schema) && ($schema['properties']['orderBy']['type'] ?? null) === 'string') {
                $updated['input_schema'] = json_encode($this->constrain($schema), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            if (is_array($definition) && ($definition['input_schema']['properties']['orderBy']['type'] ?? null) === 'string') {
                $definition['input_schema'] = $this->constrain($definition['input_schema']);
                $updated['tool_definition'] = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            if ($updated === []) continue;
            DB::transaction(function () use ($route, $updated): void {
                DB::table('api_routes')->where('id', $route->id)->where('tenant_id', $route->tenant_id)->update([...$updated, 'updated_at' => now()]);
                DB::table('api_route_parameters')->where('api_route_id', $route->id)->where('tenant_id', $route->tenant_id)
                    ->where('name', 'orderBy')->where('source', 'llm')->update(['description' => self::DESCRIPTION]);
                $parameters = DB::table('api_route_parameters')->where('api_route_id', $route->id)->where('tenant_id', $route->tenant_id)
                    ->orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order']);
                if ($parameters->contains(fn ($parameter) => $parameter->sort_order < 0)) {
                    // Legacy type=json was at -1. Keep the same relative order
                    // using indices accepted by the endpoint editor/test API.
                    foreach ($parameters as $index => $parameter) {
                        DB::table('api_route_parameters')->where('id', $parameter->id)->where('tenant_id', $route->tenant_id)
                            ->update(['sort_order' => $index]);
                    }
                }
            });
        }
    }

    private function constrain(array $schema): array
    {
        $schema['properties']['orderBy'] = [...$schema['properties']['orderBy'], 'type' => 'string',
            'enum' => ['data', 'num_fattura'], 'pattern' => '^(?:data|num_fattura)$', 'default' => 'data', 'description' => self::DESCRIPTION];
        return $schema;
    }

    public function down(): void
    {
        // Data repair: rolling code back must not reintroduce an invalid API contract.
    }
};
