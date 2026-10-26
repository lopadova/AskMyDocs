<?php

declare(strict_types=1);
namespace App\ApiTools;

use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;

/** Semantic, host-native fingerprints. Credentials and presentation never participate. */
final class ContentHasher
{
    public function hash(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function canonical(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) { return $value->value; }
        if (! is_array($value)) { return $value; }
        if (! array_is_list($value)) { ksort($value, SORT_STRING); }
        return array_map(fn ($v) => $this->canonical($v), $value);
    }

    public function definition(array $definition): array
    {
        unset($definition['metadata'], $definition['revision'], $definition['created_at'], $definition['updated_at']);
        foreach ($definition['steps'] ?? [] as $i => $step) {
            unset($step['name'], $step['position'], $step['expanded']);
            // Explicit waits are a set. Step order remains meaningful for deterministic composition.
            $step['wait_for'] = $step['wait_for'] ?? [];
            sort($step['wait_for']);
            $definition['steps'][$i] = $step;
        }
        return $definition;
    }

    public function tool(array $definition, string $tenantId, string $projectKey): string
    {
        $routes = [];
        foreach ($definition['steps'] ?? [] as $step) {
            if (($step['operation'] ?? 'api') !== 'api') { continue; }
            $route = ApiRoute::query()->forTenant($tenantId)->whereKey($step['route_id'] ?? 0)->with(['parameters', 'connector'])->first();
            $routes[(string) ($step['route_id'] ?? 0)] = $route ? $this->endpoint($route) : 'missing';
        }
        return $this->hash(['definition' => $this->definition($definition), 'dependencies' => $routes]);
    }

    public function endpoint(ApiRoute $route): string { return $this->hash($this->endpointContent($route)); }

    public function endpointContent(ApiRoute $route): array
    {
        $route->loadMissing(['parameters', 'connector']);
        $profile = $route->effectiveAuthProfile();
        $headers = [];
        foreach ($route->connector?->headers ?? [] as $key => $value) {
            $headers[strtolower($key)] = ConnectorSecretGuard::sensitive($key) ? '[secret]' : $value;
        }
        $parameters = $route->parameters->map(function ($p): array {
            $data = $p->only(['name','required','value','secret_ref']);
            $data['location'] = $p->location->value;
            $data['source'] = $p->source->value;
            $data['type'] = $p->type->value;
            if ($p->source->value === 'secret' || ConnectorSecretGuard::sensitive($p->name)
               ) {
                $data['value'] = '[secret]';
            }
            return $data;
        })->sortBy(fn ($p) => $p['location'].'/'.$p['name'])->values()->all();
        return [
            'route' => $route->only(['http_method','url','input_schema','output_schema','output_transform','pagination','endpoint_type','items_path','timeout_ms','cache_ttl_s','rate_limit']),
            'parameters' => $parameters,
            'connector' => ['base_url' => $route->connector?->base_url, 'headers' => $headers],
            'auth' => $profile ? ['type' => $profile->type->value, 'config' => array_intersect_key($profile->config ?? [], array_flip(['in','name','header_name','token_url','scope','auth_style']))] : null,
        ];
    }
}
