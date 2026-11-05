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
        $definition['input_schema'] = $this->schemaContent($definition['input_schema'] ?? []);
        foreach ($definition['steps'] ?? [] as $i => $step) {
            if (isset($step['output_schema'])) { $definition['steps'][$i]['output_schema'] = $this->schemaContent($step['output_schema']); }
        }
        foreach ($definition['tests'] ?? [] as $i => $test) { unset($definition['tests'][$i]['name']); }
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
        $publicCredentials = array_intersect_key($profile?->credentials ?? [], array_flip(match ($profile?->type->value) { 'basic'=>['username'], 'oauth2_cc'=>['client_id'], default=>[] }));
        $secretValues = [];
        $privateCredentials = array_diff_key($profile?->credentials ?? [], $publicCredentials);
        array_walk_recursive($privateCredentials,function ($v) use (&$secretValues): void { if (is_string($v) && $v !== '') { $secretValues[]=$v; } });
        $isSecret = static function ($v) use ($secretValues): bool { return is_string($v) && in_array($v,$secretValues,true); };
        $headers = [];
        foreach ($route->connector?->headers ?? [] as $key => $value) {
            $headers[strtolower($key)] = (ConnectorSecretGuard::sensitive($key) || $isSecret($value)) ? '[secret]' : $value;
        }
        $parameters = $route->parameters->map(function ($p) use ($isSecret): array {
            $data = $p->only(['name','required','value','secret_ref']);
            $data['location'] = $p->location->value;
            $data['source'] = $p->source->value;
            $data['type'] = $p->type->value;
            if ($p->source->value === 'secret' || ConnectorSecretGuard::sensitive($p->name) || $isSecret($p->value)
               ) {
                $data['value'] = '[secret]';
            }
            return $data;
        })->sortBy(fn ($p) => $p['location'].'/'.$p['name'])->values()->all();
        $routeContent = $route->only(['http_method','url','input_schema','output_schema','output_transform','pagination','endpoint_type','items_path','identity_fields','timeout_ms','cache_ttl_s','rate_limit']);
        foreach (['input_schema','output_schema'] as $key) { $routeContent[$key] = $this->schemaContent($routeContent[$key] ?? []); }
        return [
            'route' => $routeContent,
            'parameters' => $parameters,
            'connector' => ['base_url' => $route->connector?->base_url, 'headers' => $headers],
            'auth' => $profile ? ['type' => $profile->type->value, 'public_credentials'=>$publicCredentials, 'config' => array_intersect_key($profile->config ?? [], array_flip(['in','name','header_name','token_url','scope','auth_style']))] : null,
        ];
    }
    private function schemaContent(array $schema): array
    {
        foreach (['description','title','example','examples','$comment'] as $key) { unset($schema[$key]); }
        foreach ($schema['properties'] ?? [] as $key=>$property) { $schema['properties'][$key]=$this->schemaContent($property); }
        if (isset($schema['items']) && is_array($schema['items'])) { $schema['items']=$this->schemaContent($schema['items']); }
        return $schema;
    }
}
