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

    public const VERSION = 1;

    private const PUBLIC_HEADERS = ['accept', 'accept-language', 'content-type', 'user-agent'];

    private const AUTH_CONFIG_FIELDS = ['in', 'name', 'header_name', 'token_url', 'scope', 'auth_style'];

    private const CONNECTOR_FIELDS = ['name', 'description', 'project_key', 'base_url', 'headers', 'is_active'];

    private const ROUTE_FIELDS = [
        'name', 'slug', 'description', 'http_method', 'url', 'mode', 'status',
        'endpoint_type', 'endpoint_type_locked', 'items_path', 'identity_fields', 'timeout_ms',
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
        $tenant = app(\App\Support\TenantContext::class)->current();
        $scope = fn ($query) => $query->where('tenant_id', $tenant);
        $connector->load(['authProfiles' => $scope, 'routes' => $scope, 'routes.parameters' => $scope, 'relations' => $scope]);
        $secrets = [];
        $data = [
            '_meta' => ['format' => self::FORMAT, 'version' => self::VERSION],
            'connector' => Arr::only($connector->attributesToArray(), self::CONNECTOR_FIELDS),
            'default_auth_profile_ref' => $this->profileRef($connector->default_auth_profile_id),
            'auth_profiles' => [], 'routes' => [], 'relations' => [],
        ];
        // Legacy shared authentication headers are also write-only in the export.
        $data['connector']['secret_headers'] = [];
        foreach ($data['connector']['headers'] ?? [] as $name => $value) {
            if (! in_array(strtolower((string) $name), self::PUBLIC_HEADERS, true)) {
                if (self::sensitiveName((string) $name) && is_string($value) && $value !== '') {
                    $secrets[] = $value;
                }
                $data['connector']['secret_headers'][] = (string) $name;
                unset($data['connector']['headers'][$name]);
            }
        }
        foreach ($connector->authProfiles as $profile) {
            $credentials = $profile->credentials ?? [];
            $public = Arr::only($credentials, self::PUBLIC_FIELDS[$profile->type->value] ?? []);
            $private = Arr::except($credentials, array_keys($public));
            array_walk_recursive($private, function ($value) use (&$secrets): void {
                if (is_string($value) && $value !== '') {
                    $secrets[] = $value;
                }
            });
            $data['auth_profiles'][] = [
                'ref' => $this->profileRef($profile->id), 'type' => $profile->type->value,
                'config' => $profile->config === null ? null : Arr::only($profile->config, self::AUTH_CONFIG_FIELDS), 'public_credentials' => $public,
                'secret_fields' => array_values(array_diff(array_keys($credentials), [...array_keys($public), 'headers'])),
                'secret_headers' => array_keys(is_array($credentials['headers'] ?? null) ? $credentials['headers'] : []),
            ];
        }
        foreach ($connector->routes as $route) {
            $row = Arr::only($route->attributesToArray(), self::ROUTE_FIELDS);
            $row['identity_fields'] = $route->identity_fields ?? [];
            $row['ref'] = 'route-'.$route->id;
            $row['auth_profile_ref'] = $this->profileRef($route->auth_profile_id);
            $row['parameters'] = $route->parameters->map(function ($parameter) use (&$secrets): array {
                $row = Arr::only($parameter->attributesToArray(), self::PARAMETER_FIELDS);
                if ($row['source'] === 'secret') {
                    if (is_string($row['value'] ?? null) && $row['value'] !== '') {
                        $secrets[] = $row['value'];
                    }
                    $row['value'] = null;
                }

                return $row;
            })->all();
            foreach ($row['parameters'] as &$parameter) {
                if ($parameter['source'] === 'fixed' && (self::sensitiveName($parameter['name'])
                    || ($parameter['location'] === 'header' && ! in_array(strtolower($parameter['name']), self::PUBLIC_HEADERS, true))
                    || in_array($parameter['value'], $secrets, true))) {
                    if (is_string($parameter['value']) && $parameter['value'] !== '') {
                        $secrets[] = $parameter['value'];
                    }
                    $parameter['source'] = 'secret';
                    $parameter['secret_ref'] = 'parameter_'.count($data['routes']).'_'.count($secrets);
                    $parameter['value'] = null;
                    if (($row['auth_profile_ref'] ?? $data['default_auth_profile_ref']) === null) {
                        $row['auth_profile_ref'] = 'auth-export-'.count($data['routes']);
                        $data['auth_profiles'][] = ['ref' => $row['auth_profile_ref'], 'type' => 'none', 'config' => null,
                            'public_credentials' => [], 'secret_fields' => [], 'secret_headers' => []];
                    }
                }
            }
            unset($parameter);
            $row['param_mapping'] = $this->parameterMapping($row['parameters']);
            $data['routes'][] = $row;
        }
        foreach ($connector->relations as $relation) {
            $data['relations'][] = [
                ...Arr::only($relation->attributesToArray(), ['name', 'description', 'field_map', 'sort_order']),
                'list_route_ref' => 'route-'.$relation->list_route_id,
                'detail_route_ref' => 'route-'.$relation->detail_route_id,
            ];
        }

        // Fail closed if a credential was copied into prose, URL, schema or other public data.
        $secrets = array_unique($secrets);
        array_walk_recursive($data, function ($value) use ($secrets): void {
            if (! is_string($value)) {
                return;
            }
            foreach ($secrets as $secret) {
                if ($value === $secret || (strlen($secret) >= 4 && str_contains($value, $secret))) {
                    throw ValidationException::withMessages(['config' => 'Credential material was found in public configuration. Move it to an authentication profile or secret parameter before exporting.']);
                }
            }
        });

        return $this->validate($data);
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
            } elseif (preg_match('/[\r\n\x00]/', $value)) {
                $errors['credentials.'.$field['profile_ref'].'.'.$field['key']] = 'Credentials cannot contain line breaks or NUL bytes.';
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
        try {
            $encoded = json_encode($blob, JSON_THROW_ON_ERROR, 64);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['config' => 'Configuration must be valid JSON with a maximum nesting depth of 64.']);
        }
        if (strlen($encoded) > ConnectorPackageService::MAX_BYTES) {
            throw ValidationException::withMessages(['config' => 'Configuration exceeds the 5 MiB limit.']);
        }
        // v1 previously ignored local identity metadata. Retain that compatibility explicitly.
        foreach (['connector', 'auth_profiles.*', 'routes.*', 'routes.*.parameters.*', 'relations.*'] as $path) {
            // Identity fields are never used as import references or destination scope.
            $this->stripIdentities($blob, explode('.', $path));
        }
        $rules = [
            '_meta' => ['required', 'array:format,version'], '_meta.format' => ['required', Rule::in([self::FORMAT])],
            '_meta.version' => ['required', 'integer', Rule::in([self::VERSION])],
            'connector' => ['required', 'array:'.implode(',', [...self::CONNECTOR_FIELDS, 'secret_headers'])],
            'connector.secret_headers' => ['sometimes', 'array', 'list', 'max:100'],
            'connector.secret_headers.*' => ['required', 'string', 'max:128', 'distinct'],
            'default_auth_profile_ref' => ['nullable', 'string'],
            'auth_profiles' => ['present', 'array', 'list', 'max:100'],
            'auth_profiles.*' => ['array:ref,type,config,public_credentials,secret_fields,secret_headers'],
            'auth_profiles.*.ref' => ['required', 'string', 'regex:/^auth-[a-zA-Z0-9_-]+$/', 'max:128', 'distinct'],
            'auth_profiles.*.type' => ['required', Rule::in(AuthType::values())],
            'auth_profiles.*.config' => ['nullable', 'array:'.implode(',', self::AUTH_CONFIG_FIELDS)],
            'auth_profiles.*.config.in' => ['nullable', Rule::in(['header', 'query'])],
            'auth_profiles.*.config.auth_style' => ['nullable', Rule::in(['body', 'basic'])],
            'auth_profiles.*.config.scope' => ['nullable', 'string', 'max:4096'],
            'auth_profiles.*.config.header_name' => ['nullable', 'string', 'max:128'],
            'auth_profiles.*.config.name' => ['nullable', 'string', 'max:128'],
            'auth_profiles.*.config.token_url' => ['nullable', 'string', 'url', 'max:2048'],
            'auth_profiles.*.public_credentials' => ['sometimes', 'array:username,client_id'],
            'auth_profiles.*.public_credentials.*' => ['string'],
            'auth_profiles.*.secret_fields' => ['sometimes', 'array', 'list', 'max:100'],
            'auth_profiles.*.secret_fields.*' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'auth_profiles.*.secret_headers' => ['sometimes', 'array', 'list', 'max:100'],
            'auth_profiles.*.secret_headers.*' => ['required', 'string', 'max:128'],
            'routes' => ['present', 'array', 'list', 'max:1000'],
            'routes.*' => ['array:'.implode(',', [...self::ROUTE_FIELDS, 'ref', 'auth_profile_ref', 'parameters'])],
            'routes.*.ref' => ['required', 'string', 'max:128', 'distinct'],
            'routes.*.slug' => ['required', 'string', 'max:96', 'regex:/^[a-zA-Z0-9_]+$/', 'distinct'],
            'routes.*.status' => ['required', Rule::in(RouteStatus::values())],
            'routes.*.mode' => ['required', Rule::in(['tool', 'ingest', 'both'])],
            'routes.*.endpoint_type' => ['required', Rule::in(EndpointType::values())],
            'routes.*.endpoint_type_locked' => ['required', 'boolean'],
            'routes.*.auth_profile_ref' => ['nullable', 'string'],
            'routes.*.parameters' => ['present', 'array', 'list', 'max:500'],
            'routes.*.parameters.*' => ['array:'.implode(',', self::PARAMETER_FIELDS)],
            'relations' => ['present', 'array', 'list', 'max:1000'],
            'relations.*' => ['array:name,description,field_map,sort_order,list_route_ref,detail_route_ref'],
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
        $rules['routes.*.parameters.*.secret_ref'] = ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/'];
        foreach ((new StoreRouteRelationRequest)->rules() as $key => $rule) {
            if (! in_array($key, ['list_route_id', 'detail_route_id'])) {
                $rules['relations.*.'.$key] = $rule;
            }
        }
        $rules['routes.*.output_transform'] = ['nullable', 'array:include,exclude'];
        $rules['relations.*.field_map'] = ['required', 'array', 'list', 'min:1', 'max:500'];
        $rules['relations.*.field_map.*'] = ['array:from,to_param,to_location'];
        $rules['routes.*.tool_definition.name'] = ['required_with:routes.*.tool_definition', 'string', 'max:96'];
        $rules['routes.*.tool_definition'] = ['nullable', 'array:name,description,input_schema'];
        $rules['routes.*.tool_definition.description'] = ['required_with:routes.*.tool_definition', 'string'];
        $rules['routes.*.tool_definition.input_schema'] = ['required_with:routes.*.tool_definition', 'array'];
        foreach (['include', 'exclude'] as $key) {
            $rules['routes.*.output_transform.'.$key] = ['sometimes', 'array', 'list', 'max:500'];
            $rules['routes.*.output_transform.'.$key.'.*'] = ['string', 'max:255'];
        }
        $config = Validator::make($blob, $rules, [
            '_meta.version.in' => 'Unsupported API connector configuration version. Supported version: 1.',
            '_meta.format.in' => 'This is not an AskMyDocs API connector configuration.',
        ])->validate();
        if (array_diff(array_keys($blob), ['_meta', 'connector', 'default_auth_profile_ref', 'auth_profiles', 'routes', 'relations']) !== []) {
            throw ValidationException::withMessages(['config' => 'Unknown configuration fields are not supported by version 1.']);
        }
        $this->assertPortableUrl($config['connector']['base_url'] ?? null, 'connector.base_url');
        foreach ($config['connector']['headers'] ?? [] as $name => $value) {
            $this->assertHeader((string) $name);
            if (! in_array(strtolower((string) $name), self::PUBLIC_HEADERS, true)) {
                throw ValidationException::withMessages(['connector.headers' => 'Private shared headers must use secret_headers and be supplied separately during import.']);
            }
            $this->assertHeaderValue($value);
        }
        foreach ($config['connector']['secret_headers'] ?? [] as $name) {
            $this->assertHeader($name);
        }
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
            $this->assertPortableUrl($profile['config']['token_url'] ?? null, 'auth_profiles.'.$profile['ref'].'.config.token_url');
            if ($profile['type'] === 'oauth2_cc' && empty($profile['config']['token_url'])) {
                throw ValidationException::withMessages(['auth_profiles' => 'OAuth2 profiles require a token URL.']);
            }
            foreach (array_keys($profile['public_credentials'] ?? []) as $key) {
                if (! in_array($key, self::PUBLIC_FIELDS[$profile['type']] ?? [], true)) {
                    throw ValidationException::withMessages(['auth_profiles' => 'Invalid public credential for this authentication type.']);
                }
            }
            if (in_array('headers', $profile['secret_fields'] ?? [], true)) {
                throw ValidationException::withMessages(['auth_profiles' => 'The headers credential key is reserved. Use secret_headers for custom authentication headers.']);
            }
            foreach ($profile['secret_headers'] ?? [] as $name) {
                $this->assertHeader($name);
            }
            foreach (['name', 'header_name'] as $key) {
                if (isset($profile['config'][$key])) {
                    if ($key === 'name' && $profile['type'] === 'api_key' && ($profile['config']['in'] ?? 'header') === 'query') {
                        $this->assertHeaderValue($profile['config'][$key]);
                    } else {
                        $this->assertHeader($profile['config'][$key]);
                    }
                }
            }
        }
        $parameterCount = 0;
        foreach ($config['routes'] as $index => $route) {
            $this->assertPortableUrl($route['url'], 'routes.'.$index.'.url');
            $assertProfile($route['auth_profile_ref'] ?? null, 'routes.'.$index.'.auth_profile_ref');
            foreach (['input_schema', 'output_schema', 'pagination', 'param_mapping'] as $key) {
                if (! empty($route[$key]) && array_is_list($route[$key])) {
                    throw ValidationException::withMessages(['routes.'.$index.'.'.$key => 'Use a JSON object for '.$key.'.']);
                }
            }
            if (isset($route['tool_definition']['input_schema']) && array_is_list($route['tool_definition']['input_schema'])) {
                throw ValidationException::withMessages(['routes.'.$index.'.tool_definition.input_schema' => 'A tool input schema must be a JSON object.']);
            }
            preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $route['url'], $tokens);
            foreach ($tokens[1] as $token) {
                if (! collect($route['parameters'])->contains(fn ($parameter) => $parameter['name'] === $token && $parameter['location'] === 'path')) {
                    throw ValidationException::withMessages(['routes.'.$index.'.parameters' => 'Every URL placeholder requires a matching path parameter.']);
                }
            }
            $names = [];
            $parameterCount += count($route['parameters']);
            if ($parameterCount > 10000) {
                throw ValidationException::withMessages(['routes' => 'A configuration may contain at most 10000 parameters in total.']);
            }
            foreach ($route['parameters'] as $parameterIndex => $parameter) {
                if (isset($names[$parameter['name']])) {
                    throw ValidationException::withMessages(['routes.'.$index.'.parameters' => 'Parameter names must be unique within an endpoint.']);
                }
                $names[$parameter['name']] = true;
                if ($parameter['location'] === 'header') {
                    $this->assertHeader($parameter['name']);
                    if ($parameter['source'] === 'fixed' && isset($parameter['value'])) {
                        $this->assertHeaderValue($parameter['value']);
                    }
                }
                if ($parameter['source'] === 'fixed' && (self::sensitiveName($parameter['name'])
                    || ($parameter['location'] === 'header' && ! in_array(strtolower($parameter['name']), self::PUBLIC_HEADERS, true)))) {
                    throw ValidationException::withMessages(['routes.'.$index.'.parameters' => 'Authentication parameters must use source=secret, a secret_ref and an authentication profile.']);
                }
                if ($parameter['source'] === 'secret' && (empty($parameter['secret_ref'])
                    || $parameter['secret_ref'] === 'headers'
                    || empty($route['auth_profile_ref'] ?? $config['default_auth_profile_ref'] ?? null))) {
                    throw ValidationException::withMessages(['routes.'.$index.'.parameters' => 'Secret parameters require a credential key and an authentication profile.']);
                }
                if ($parameter['source'] !== 'fixed') {
                    $config['routes'][$index]['parameters'][$parameterIndex]['value'] = null;
                }
                if ($parameter['location'] === 'body' && ! in_array($route['http_method'], ['POST', 'PUT', 'PATCH'], true)) {
                    throw ValidationException::withMessages(['routes.'.$index.'.parameters' => 'Body parameters require POST, PUT or PATCH.']);
                }
            }
            $config['routes'][$index]['param_mapping'] = $this->parameterMapping($route['parameters']);
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
                    $parameter['source'] === 'llm' && $parameter['name'] === $mapping['to_param']
                    && (! isset($mapping['to_location']) || $parameter['location'] === $mapping['to_location']));
            }
            if (! $valid) {
                throw ValidationException::withMessages(['relations.'.$index => 'Invalid list/detail relation or parameter mapping.']);
            }
            $pairs[$pair] = true;
        }

        return $config;
    }

    private function stripIdentities(array &$data, array $segments): void
    {
        if ($segments === []) {
            foreach (['id', 'tenant_id', 'created_at', 'updated_at', 'api_connector_id', 'auth_profile_id'] as $key) {
                unset($data[$key]);
            }
            return;
        }
        $segment = array_shift($segments);
        if ($segment === '*') {
            foreach ($data as &$row) {
                if (is_array($row)) {
                    $this->stripIdentities($row, $segments);
                }
            }
        } elseif (isset($data[$segment]) && is_array($data[$segment])) {
            $this->stripIdentities($data[$segment], $segments);
        }
    }

    private function parameterMapping(array $parameters): array
    {
        $mapping = [];
        foreach ($parameters as $parameter) {
            $entry = Arr::only($parameter, ['location', 'source']);
            if ($parameter['source'] === 'fixed') {
                $entry['value'] = $parameter['value'] ?? null;
            }
            if ($parameter['source'] === 'secret') {
                $entry['ref'] = $parameter['secret_ref'] ?? null;
            }
            $mapping[$parameter['name']] = $entry;
        }
        return $mapping;
    }

    private static function sensitiveName(string $name): bool
    {
        return (bool) preg_match('/authorization|api[-_]?key|token|secret|password|cookie|signature/i', $name);
    }

    private function assertHeader(string $name): void
    {
        if (! preg_match('/^[!#$%&\x27*+.^_`|~a-zA-Z0-9-]+$/D', $name)) {
            throw ValidationException::withMessages(['config' => 'Header names must be valid HTTP tokens.']);
        }
    }

    private function assertHeaderValue(string $value): void
    {
        if (preg_match('/[\r\n\x00]/', $value)) {
            throw ValidationException::withMessages(['config' => 'Header values cannot contain line breaks or NUL bytes.']);
        }
    }

    private function assertPortableUrl(?string $url, string $field): void
    {
        if ($url === null) {
            return;
        }
        $parts = parse_url($url);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages([$field => 'Use an absolute HTTP(S) URL without user credentials or fragments.']);
        }
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            $name = str_replace([' ', '.'], '_', urldecode(explode('=', $pair, 2)[0]));
            if (self::sensitiveName($name)) {
                throw ValidationException::withMessages([$field => 'Move URL credentials to an authentication profile or secret parameter.']);
            }
        }
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
