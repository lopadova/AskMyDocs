<?php

declare(strict_types=1);

namespace App\ApiConnectors;

use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\StoreConnectorRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\StoreRouteRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\StoreRouteRelationRequest;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Support\AuthType;
use Padosoft\AskMyDocsConnectorApi\Support\EndpointType;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Support\RouteStatus;
use RuntimeException;

/** Portable configuration only: local identities, credentials and call/test history stay local. */
final class ConnectorConfigTransferService
{
    public const FORMAT = 'askmydocs.api-connector-config';

    private const CONNECTOR_FIELDS = ['name', 'description', 'project_key', 'base_url', 'headers', 'is_active'];

    private const ROUTE_FIELDS = [
        'name', 'slug', 'description', 'http_method', 'url', 'mode', 'status',
        'endpoint_type', 'endpoint_type_locked', 'items_path', 'timeout_ms',
        'cache_ttl_s', 'rate_limit', 'input_schema', 'output_schema', 'param_mapping',
        'tool_definition', 'output_transform', 'pagination',
    ];

    private const PARAMETER_FIELDS = [
        'name', 'location', 'source', 'type', 'required', 'value', 'secret_ref', 'description', 'sort_order',
    ];

    private const CREDENTIAL_FIELDS = [
        'none' => [], 'api_key' => ['api_key'], 'bearer' => ['token'],
        'basic' => ['username', 'password'], 'custom' => [],
        'oauth2_cc' => ['client_id', 'client_secret'],
    ];

    private const PUBLIC_FIELDS = ['basic' => ['username'], 'oauth2_cc' => ['client_id']];

    public function __construct(private readonly ConnectorAdminService $admin) {}

    public function export(int $id): array
    {
        $connector = $this->admin->findConnector($id);
        $connector->load(['authProfiles', 'routes.parameters', 'relations']);
        $data = [
            '_meta' => ['format' => self::FORMAT, 'version' => 1],
            'connector' => Arr::only($connector->attributesToArray(), self::CONNECTOR_FIELDS),
            'default_auth_profile_ref' => $this->profileRef($connector->default_auth_profile_id),
            'auth_profiles' => [], 'routes' => [], 'relations' => [],
        ];
        // Legacy shared authentication headers are also write-only in the export.
        $data['connector']['secret_headers'] = [];
        foreach ($data['connector']['headers'] ?? [] as $name => $value) {
            if (preg_match('/authorization|api[-_]?key|token|secret|password|cookie/i', $name)) {
                $data['connector']['secret_headers'][] = $name;
                unset($data['connector']['headers'][$name]);
            }
        }
        foreach ($connector->authProfiles as $profile) {
            $credentials = $profile->credentials ?? [];
            $public = Arr::only($credentials, self::PUBLIC_FIELDS[$profile->type->value] ?? []);
            $data['auth_profiles'][] = [
                'ref' => $this->profileRef($profile->id), 'type' => $profile->type->value,
                'config' => $profile->config, 'public_credentials' => $public,
                'secret_fields' => array_values(array_diff(array_keys($credentials), [...array_keys($public), 'headers'])),
                'secret_headers' => array_keys(is_array($credentials['headers'] ?? null) ? $credentials['headers'] : []),
            ];
        }
        foreach ($connector->routes as $route) {
            $row = Arr::only($route->attributesToArray(), self::ROUTE_FIELDS);
            $row['ref'] = 'route-'.$route->id;
            $row['auth_profile_ref'] = $this->profileRef($route->auth_profile_id);
            $row['parameters'] = $route->parameters->map(function ($parameter): array {
                $row = Arr::only($parameter->attributesToArray(), self::PARAMETER_FIELDS);
                if ($row['source'] === 'secret') {
                    $row['value'] = null;
                }

                return $row;
            })->all();
            foreach ($row['param_mapping'] ?? [] as $key => $mapping) {
                if (is_array($mapping) && ($mapping['source'] ?? null) === 'secret') {
                    $row['param_mapping'][$key]['value'] = null;
                }
            }
            $data['routes'][] = $row;
        }
        foreach ($connector->relations as $relation) {
            $data['relations'][] = [
                ...Arr::only($relation->attributesToArray(), ['name', 'description', 'field_map', 'sort_order']),
                'list_route_ref' => 'route-'.$relation->list_route_id,
                'detail_route_ref' => 'route-'.$relation->detail_route_id,
            ];
        }

        return $data;
    }

    public function preview(array $blob): array
    {
        $config = $this->validate($blob);

        return [
            'config' => $config,
            'name' => $config['connector']['name'],
            'endpoint_count' => count($config['routes']),
            'relation_count' => count($config['relations']),
            'required_credentials' => $this->requirements($config),
        ];
    }

    public function import(array $blob, array $credentials): ApiConnector
    {
        $config = $this->validate($blob);
        $requirements = $this->requirements($config);
        $errors = [];
        foreach ($requirements as $field) {
            $value = $credentials[$field['profile_ref']][$field['key']] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $errors['credentials.'.$field['profile_ref'].'.'.$field['key']] = 'Enter '.$field['label'].'.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($config, $credentials, $requirements): ApiConnector {
            $attributes = Arr::only($config['connector'], self::CONNECTOR_FIELDS);
            $tenant = app(\App\Support\TenantContext::class)->current();
            $attributes['name'] = $this->availableName($attributes['name'], 128, fn ($name) => ApiConnector::forTenant($tenant)
                ->where('project_key', $attributes['project_key'] ?? null)->where('name', $name)->exists());
            foreach ($config['connector']['secret_headers'] ?? [] as $name) {
                $attributes['headers'][$name] = $credentials['shared-headers'][$name];
            }
            $connector = $this->admin->createConnector($attributes);
            $profiles = [];
            foreach ($config['auth_profiles'] as $profile) {
                $values = $profile['public_credentials'] ?? [];
                foreach ($requirements as $field) {
                    if ($field['profile_ref'] === $profile['ref']) {
                        if (isset($field['header_name'])) {
                            $values['headers'][$field['header_name']] = $credentials[$profile['ref']][$field['key']];
                        } else {
                            $values[$field['key']] = $credentials[$profile['ref']][$field['key']];
                        }
                    }
                }
                // Older host forms stored a single custom header as value + header_name.
                if ($profile['type'] === 'custom' && isset($values['value']) && empty($values['headers'])) {
                    $name = $profile['config']['header_name'] ?? $profile['config']['name'] ?? 'X-Custom-Auth';
                    $values['headers'] = [$name => $values['value']];
                }
                $profiles[$profile['ref']] = $this->admin->createAuthProfile($connector, [
                    'type' => $profile['type'], 'config' => $profile['config'] ?? null, 'credentials' => $values,
                ])->id;
            }
            $connector->default_auth_profile_id = $profiles[$config['default_auth_profile_ref'] ?? ''] ?? null;
            $this->save($connector);
            $routes = [];
            $slugs = [];
            foreach ($config['routes'] as $row) {
                $attributes = Arr::only($row, self::ROUTE_FIELDS);
                $attributes['slug'] = $this->availableName($row['slug'], 96, fn ($slug) => ApiRoute::forTenant($tenant)
                    ->where('project_key', $connector->projectScope())->where('slug', $slug)->exists(), '_');
                $slugs[$row['slug']] = $attributes['slug'];
                $attributes['auth_profile_id'] = $profiles[$row['auth_profile_ref'] ?? ''] ?? null;
                $route = $this->createRelated($connector->routes(), [
                    ...$attributes, 'tenant_id' => $tenant, 'project_key' => $connector->projectScope(),
                ]);
                foreach ($row['parameters'] as $parameter) {
                    $parameter = Arr::only($parameter, self::PARAMETER_FIELDS);
                    if ($parameter['source'] === 'secret') {
                        $parameter['value'] = null;
                    }
                    $this->createRelated($route->parameters(), [...$parameter, 'tenant_id' => $tenant]);
                }
                $routes[$row['ref']] = $route;
            }
            // Keep tool names and list/detail references coherent when importing a copy.
            foreach ($routes as $route) {
                if ($route->tool_definition !== null) {
                    $definition = $route->tool_definition;
                    $definition['name'] = $route->slug;
                    if (is_string($definition['description'] ?? null)) {
                        $definition['description'] = preg_replace_callback('/\b[a-zA-Z0-9_]+\b/',
                            fn ($match) => $slugs[$match[0]] ?? $match[0], $definition['description']);
                    }
                    $route->tool_definition = $definition;
                    $this->save($route);
                }
            }
            foreach ($config['relations'] as $relation) {
                $this->createRelated($connector->relations(), [
                    ...Arr::only($relation, ['name', 'description', 'field_map', 'sort_order']),
                    'tenant_id' => $tenant, 'list_route_id' => $routes[$relation['list_route_ref']]->id,
                    'detail_route_id' => $routes[$relation['detail_route_ref']]->id,
                ]);
            }

            return $connector->load(['routes', 'authProfiles', 'relations.listRoute', 'relations.detailRoute']);
        });
    }

    private function validate(array $blob): array
    {
        $rules = [
            '_meta' => ['required', 'array'], '_meta.format' => ['required', Rule::in([self::FORMAT])],
            '_meta.version' => ['required', 'integer', Rule::in([1])],
            'connector' => ['required', 'array'],
            'connector.secret_headers' => ['sometimes', 'array', 'max:100'],
            'connector.secret_headers.*' => ['required', 'string', 'max:128', 'distinct'],
            'default_auth_profile_ref' => ['nullable', 'string'],
            'auth_profiles' => ['present', 'array', 'max:100'],
            'auth_profiles.*.ref' => ['required', 'string', 'regex:/^auth-[a-zA-Z0-9_-]+$/', 'max:128', 'distinct'],
            'auth_profiles.*.type' => ['required', Rule::in(AuthType::values())],
            'auth_profiles.*.config' => ['nullable', 'array'],
            'auth_profiles.*.config.header_name' => ['nullable', 'string', 'max:128'],
            'auth_profiles.*.config.name' => ['nullable', 'string', 'max:128'],
            'auth_profiles.*.config.token_url' => ['nullable', 'string', 'url', 'max:2048'],
            'auth_profiles.*.public_credentials' => ['sometimes', 'array:username,client_id'],
            'auth_profiles.*.public_credentials.*' => ['string'],
            'auth_profiles.*.secret_fields' => ['sometimes', 'array', 'max:100'],
            'auth_profiles.*.secret_fields.*' => ['required', 'string', 'max:128'],
            'auth_profiles.*.secret_headers' => ['sometimes', 'array', 'max:100'],
            'auth_profiles.*.secret_headers.*' => ['required', 'string', 'max:128'],
            'routes' => ['present', 'array', 'max:1000'],
            'routes.*.ref' => ['required', 'string', 'max:128', 'distinct'],
            'routes.*.slug' => ['required', 'string', 'max:96', 'regex:/^[a-zA-Z0-9_]+$/', 'distinct'],
            'routes.*.status' => ['required', Rule::in(RouteStatus::values())],
            'routes.*.mode' => ['required', Rule::in(['tool', 'ingest', 'both'])],
            'routes.*.endpoint_type' => ['required', Rule::in(EndpointType::values())],
            'routes.*.endpoint_type_locked' => ['required', 'boolean'],
            'routes.*.auth_profile_ref' => ['nullable', 'string'],
            'routes.*.parameters' => ['present', 'array', 'max:500'],
            'relations' => ['present', 'array', 'max:1000'],
            'relations.*.list_route_ref' => ['required', 'string'],
            'relations.*.detail_route_ref' => ['required', 'string'],
        ];
        foreach ((new StoreConnectorRequest)->rules() as $key => $rule) {
            $rules['connector.'.$key] = $rule;
        }
        foreach ((new StoreRouteRequest)->rules() as $key => $rule) {
            if (! in_array($key, ['auth_profile_id', 'slug', 'endpoint_type', 'parameters', 'mode'])) {
                // Prefix conditional parameter rules to their portable envelope path.
                $rules['routes.*.'.$key] = array_map(fn ($item) => $item === 'required_with:parameters'
                    ? 'required_with:routes.*.parameters' : $item, $rule);
            }
        }
        foreach (['input_schema', 'output_schema', 'param_mapping', 'tool_definition'] as $field) {
            $rules['routes.*.'.$field] = ['nullable', 'array'];
        }
        $rules['routes.*.parameters.*.type'] = ['required', Rule::in(ParamType::values())];
        $rules['routes.*.parameters.*.required'] = ['required', 'boolean'];
        $rules['routes.*.parameters.*.sort_order'] = ['required', 'integer', 'min:0'];
        foreach ((new StoreRouteRelationRequest)->rules() as $key => $rule) {
            if (! in_array($key, ['list_route_id', 'detail_route_id'])) {
                $rules['relations.*.'.$key] = $rule;
            }
        }
        $config = Validator::make($blob, $rules)->validate();
        // Whitelists are applied again at persistence; unknown tenant/id/history fields cannot be replayed.
        $profiles = array_column($config['auth_profiles'], null, 'ref');
        $routes = array_column($config['routes'], null, 'ref');
        $assertProfile = function (?string $ref, string $field) use ($profiles): void {
            if ($ref !== null && ! isset($profiles[$ref])) {
                throw ValidationException::withMessages([$field => 'Unknown authentication profile reference.']);
            }
        };
        $assertProfile($config['default_auth_profile_ref'] ?? null, 'default_auth_profile_ref');
        foreach ($profiles as $profile) {
            if ($profile['type'] === 'oauth2_cc' && empty($profile['config']['token_url'])) {
                throw ValidationException::withMessages(['auth_profiles' => 'OAuth2 profiles require a token URL.']);
            }
            foreach (array_keys($profile['public_credentials'] ?? []) as $key) {
                if (! in_array($key, self::PUBLIC_FIELDS[$profile['type']] ?? [], true)) {
                    throw ValidationException::withMessages(['auth_profiles' => 'Invalid public credential for this authentication type.']);
                }
            }
        }
        foreach ($config['routes'] as $index => $route) {
            $assertProfile($route['auth_profile_ref'] ?? null, 'routes.'.$index.'.auth_profile_ref');
            foreach ($route['parameters'] as $parameter) {
                if ($parameter['source'] === 'secret' && (empty($parameter['secret_ref'])
                    || empty($route['auth_profile_ref'] ?? $config['default_auth_profile_ref'] ?? null))) {
                    throw ValidationException::withMessages(['routes.'.$index.'.parameters' => 'Secret parameters require a credential key and an authentication profile.']);
                }
            }
        }
        $pairs = [];
        foreach ($config['relations'] as $index => $relation) {
            $list = $routes[$relation['list_route_ref']] ?? null;
            $detail = $routes[$relation['detail_route_ref']] ?? null;
            $pair = $relation['list_route_ref'].'|'.$relation['detail_route_ref'];
            $valid = $list !== null && $detail !== null && $list['endpoint_type'] === 'list'
                && $detail['endpoint_type'] === 'detail' && ! isset($pairs[$pair]);
            foreach ($relation['field_map'] as $mapping) {
                $valid = $valid && collect($detail['parameters'] ?? [])->contains(fn ($parameter) =>
                    $parameter['source'] === 'llm' && $parameter['name'] === $mapping['to_param']);
            }
            if (! $valid) {
                throw ValidationException::withMessages(['relations.'.$index => 'Invalid list/detail relation or parameter mapping.']);
            }
            $pairs[$pair] = true;
        }

        return $config;
    }

    private function requirements(array $config): array
    {
        $fields = [];
        $labels = ['api_key' => 'API key', 'key' => 'API key', 'token' => 'Bearer token', 'access_token' => 'Bearer token', 'username' => 'Username',
            'password' => 'Password', 'value' => 'Header value', 'client_id' => 'Client ID', 'client_secret' => 'Client secret'];
        foreach ($config['auth_profiles'] as $index => $profile) {
            $secretFields = $profile['secret_fields'] ?? [];
            $typeFields = self::CREDENTIAL_FIELDS[$profile['type']];
            if ($profile['type'] === 'api_key' && in_array('key', $secretFields, true)) {
                $typeFields = ['key'];
            }
            if ($profile['type'] === 'bearer' && in_array('access_token', $secretFields, true)) {
                $typeFields = ['access_token'];
            }
            $keys = array_merge($typeFields, $secretFields);
            $endpointNames = [];
            foreach ($config['routes'] as $route) {
                if (($route['auth_profile_ref'] ?? $config['default_auth_profile_ref'] ?? null) === $profile['ref']) {
                    $endpointNames[] = $route['name'];
                    foreach ($route['parameters'] as $parameter) {
                        if ($parameter['source'] === 'secret') {
                            $keys[] = $parameter['secret_ref'];
                        }
                    }
                }
            }
            $profileLabel = ($config['default_auth_profile_ref'] ?? null) === $profile['ref']
                ? 'Default authentication'
                : ($endpointNames === [] ? 'Authentication profile '.($index + 1) : implode(', ', array_slice($endpointNames, 0, 2)));
            foreach (array_unique($keys) as $key) {
                if (! isset($profile['public_credentials'][$key]) || $profile['public_credentials'][$key] === '') {
                    $fields[] = ['profile_ref' => $profile['ref'], 'profile_label' => $profileLabel, 'type' => $profile['type'], 'key' => $key,
                        'label' => $labels[$key] ?? $key, 'secret' => ! in_array($key, self::PUBLIC_FIELDS[$profile['type']] ?? [], true)];
                }
            }
            $headers = $profile['secret_headers'] ?? [];
            if ($profile['type'] === 'custom' && $headers === [] && $secretFields === []) {
                $headers = [$profile['config']['header_name'] ?? $profile['config']['name'] ?? 'X-Custom-Auth'];
            }
            foreach ($headers as $name) {
                $fields[] = ['profile_ref' => $profile['ref'], 'profile_label' => $profileLabel, 'type' => $profile['type'], 'key' => 'header:'.$name,
                    'label' => $name, 'secret' => true, 'header_name' => $name];
            }
        }
        foreach ($config['connector']['secret_headers'] ?? [] as $name) {
            $fields[] = ['profile_ref' => 'shared-headers', 'profile_label' => 'Shared headers', 'type' => 'custom', 'key' => $name,
                'label' => $name, 'secret' => true];
        }

        return $fields;
    }

    private function availableName(string $original, int $limit, callable $exists, string $separator = ' '): string
    {
        $name = $original;
        for ($copy = 2; $exists($name); $copy++) {
            $suffix = $separator === '_' ? '_'.$copy : ' ('.$copy.')';
            $name = mb_substr($original, 0, $limit - strlen($suffix)).$suffix;
        }

        return $name;
    }

    private function profileRef(?int $id): ?string
    {
        return $id === null ? null : 'auth-'.$id;
    }

    private function createRelated(HasMany $relation, array $attributes): Model
    {
        $model = $relation->make($attributes);
        $this->save($model);

        return $model;
    }

    private function save(Model $model): void
    {
        if (! $model->saveOrFail()) {
            throw new RuntimeException('Failed to persist '.class_basename($model).'.');
        }
    }
}
