<?php

declare(strict_types=1);

// Keep endpoint identity configuration in the canonical connector contract until
// connector-api ships it upstream. Values live on the route, outside regenerated
// output schemas, so tests and description generation cannot erase user choices.
$packagePath = $argv[1] ?? __DIR__.'/../../vendor/padosoft/askmydocs-connector-api';
$changes = [
    'src/Models/ApiRoute.php' => [
        "        'items_path'," => "        'items_path',\n        'identity_fields',",
        "        'items_path' => 'string'," => "        'items_path' => 'string',\n        'identity_fields' => 'array',",
    ],
    'src/Http/Resources/ApiRouteResource.php' => [
        '            \'items_path\' => $this->items_path,' => '            \'items_path\' => $this->items_path,'."\n".'            \'identity_fields\' => $this->identity_fields ?? [],',
    ],
    'src/Support/RouteConfig.php' => [
        '                \'items_path\' => $route->items_path,' => '                \'items_path\' => $route->items_path,'."\n".'                \'identity_fields\' => $route->identity_fields ?? [],',
        '            \'items_path\' => $response[\'items_path\'] ?? null,' => '            \'items_path\' => $response[\'items_path\'] ?? null,'."\n".'            \'identity_fields\' => \App\ApiConnectors\EndpointIdentityFields::normalize($response[\'identity_fields\'] ?? null),',
    ],
    'src/Support/RouteConfigSchema.php' => [
        "                        'items_path' => ['type' => ['string', 'null'], 'maxLength' => 255]," => <<<'PHP'
                        'items_path' => ['type' => ['string', 'null'], 'maxLength' => 255],
                        'identity_fields' => [
                            'type' => 'array', 'maxItems' => 12, 'uniqueItems' => true,
                            'items' => ['type' => 'string', 'maxLength' => 128, 'pattern' => '^[a-zA-Z0-9_-]+$'],
                        ],
PHP,
        '                \'items_path\' => self::str($response[\'items_path\'] ?? null),' => '                \'items_path\' => self::str($response[\'items_path\'] ?? null),'."\n".'                \'identity_fields\' => \App\ApiConnectors\EndpointIdentityFields::normalize($response[\'identity_fields\'] ?? null),',
    ],
    'src/Http/Requests/Concerns/ValidatesRouteConfig.php' => [
        "            'config.response.items_path' => ['nullable', 'string', 'max:255']," => <<<'PHP'
            'config.response.items_path' => ['nullable', 'string', 'max:255'],
            'config.response.identity_fields' => ['sometimes', 'array', 'list', 'max:12'],
            'config.response.identity_fields.*' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/', 'distinct:strict'],
PHP,
    ],
    'src/Http/Requests/StoreRouteRequest.php' => [
        "            'items_path' => ['nullable', 'string', 'max:255']," => <<<'PHP'
            'items_path' => ['nullable', 'string', 'max:255'],
            'identity_fields' => ['sometimes', 'array', 'list', 'max:12'],
            'identity_fields.*' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/', 'distinct:strict'],
PHP,
    ],
    'src/Http/Requests/UpdateRouteRequest.php' => [
        "            'items_path' => ['sometimes', 'nullable', 'string', 'max:255']," => <<<'PHP'
            'items_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'identity_fields' => ['sometimes', 'array', 'list', 'max:12'],
            'identity_fields.*' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/', 'distinct:strict'],
PHP,
    ],
    'src/Services/ConnectorAdminService.php' => [
        '        $this->applyEndpointType($route, $data);' => <<<'PHP'
        if (array_key_exists('identity_fields', $data)) {
            $route->identity_fields = \App\ApiConnectors\EndpointIdentityFields::normalize($data['identity_fields']);
        }
        $this->applyEndpointType($route, $data);
PHP,
        '        $route->items_path = is_string($flat[\'items_path\'] ?? null) ? $flat[\'items_path\'] : null;' => <<<'PHP'
        $route->items_path = is_string($flat['items_path'] ?? null) ? $flat['items_path'] : null;
        $route->identity_fields = \App\ApiConnectors\EndpointIdentityFields::normalize($flat['identity_fields'] ?? null);
PHP,
        '        $base[\'response\'] = $response;' => <<<'PHP'
        // Identity fields are an operator choice, never guessed or erased by AI.
        $response['identity_fields'] = \App\ApiConnectors\EndpointIdentityFields::normalize($input['response']['identity_fields'] ?? null);
        $base['response'] = $response;
PHP,
        '                \'items_path\' => $suggestion[\'items_path\'] ?? null,' => <<<'PHP'
                'items_path' => $suggestion['items_path'] ?? null,
                'identity_fields' => \App\ApiConnectors\EndpointIdentityFields::normalize($input['response']['identity_fields'] ?? null),
PHP,
    ],
];

try {
    $patched = [];
    foreach ($changes as $relative => $replacements) {
        $path = $packagePath.'/'.$relative;
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Cannot read API connector source: '.$relative);
        }
        foreach ($replacements as $before => $after) {
            if (str_contains($source, $after)) {
                continue;
            }
            if (substr_count($source, $before) !== 1) {
                throw new RuntimeException('API connector source changed; review the identity-fields patch: '.$relative);
            }
            $source = str_replace($before, $after, $source);
        }
        $patched[$path] = $source;
    }
    // Check every anchor before writing, so an incompatible package fails closed.
    foreach ($patched as $path => $source) {
        if (file_get_contents($path) !== $source && file_put_contents($path, $source) === false) {
            throw new RuntimeException('Cannot write API connector identity-fields patch.');
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
