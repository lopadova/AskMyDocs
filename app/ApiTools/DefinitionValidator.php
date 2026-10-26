<?php

declare(strict_types=1);
namespace App\ApiTools;

use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Validator;

final class DefinitionValidator
{
    public const OPERATIONS = ['api','select','filter','sort','limit','compose','join'];

    public function __construct(private ToolAccess $access, private ConnectorSecretGuard $secrets) {}

    public function validate(array $definition, string $tenantId, string $projectKey): array
    {
        $definition = json_decode(json_encode($definition, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        $this->check(($definition['version'] ?? null) === 1, 'version', 'Unsupported tool definition version.');
        $this->check(($definition['input_schema']['type'] ?? '') === 'object', 'input_schema', 'Tool inputs must have an object schema.');
        $steps = $definition['steps'] ?? [];
        $this->check(is_array($steps) && array_is_list($steps) && count($steps) > 0 && count($steps) <= config('api-tools.max_steps', 40), 'steps', 'Provide between 1 and 40 steps.');
        $routes = $this->access->routes($tenantId, $projectKey)->keyBy('id');
        $ids = [];
        $schemas = [];
        foreach ($steps as $i => $step) {
            $step += ['name'=>$step['id'] ?? '', 'operation'=>'api', 'bindings'=>[], 'wait_for'=>[], 'collection'=>['mode'=>'single']];
            $steps[$i] = $step;
            $definition['steps'][$i] = $step;
            $id = $step['id'] ?? '';
            $this->check(is_string($id) && preg_match('/^[a-z][a-z0-9_]{0,47}$/', $id) === 1 && $id !== 'tool_output' && ! isset($ids[$id]), "steps.$i.id", 'Step identifiers must be unique stable keys.');
            $ids[$id] = $i;
            $op = $step['operation'] ?? 'api';
            $this->check(in_array($op, self::OPERATIONS, true), "steps.$i.operation", 'Unknown operation.');
            if ($op === 'api') {
                $this->check(is_int($step['route_id'] ?? null) && $step['route_id'] > 0, "steps.$i.route_id", 'Choose an endpoint identifier.');
                $route = $routes->get($step['route_id'] ?? 0);
                $this->check($route !== null, "steps.$i.route_id", 'Endpoint is unavailable in this tenant/project or is not read-only.');
                $schemas[$id] = $route->output_schema ?? [];
                $mode = $step['collection']['mode'] ?? 'single';
                $this->check(in_array($mode, ['single','bounded'], true), "steps.$i.collection", 'Collection must be single or explicitly bounded.');
                if ($mode === 'bounded') {
                    $this->bounded($step['collection']['max_pages'] ?? null, config('api-tools.max_pages',20), "steps.$i.collection.max_pages");
                    $this->bounded($step['collection']['max_items'] ?? null, config('api-tools.max_items',100), "steps.$i.collection.max_items");
                    $this->check(in_array($route->pagination['type'] ?? '', ['page','cursor'], true), "steps.$i.collection", 'Bounded collection needs configured page/cursor pagination.');
                    $schemas[$id] = ['type'=>'array','items'=>[]];
                }
            } else { $schemas[$id] = $step['output_schema'] ?? []; }
            if (isset($step['iterate'])) {
                $itemSchema = $schemas[$id];
                $listSchema = ['type'=>'array','items'=>$itemSchema];
                $schemas[$id] = ($step['iterate']['allow_partial'] ?? false) ? ['type'=>'object','properties'=>['items'=>$listSchema,'errors'=>['type'=>'array'],'_partial'=>['type'=>'boolean']]] : $listSchema;
                $this->bounded($step['iterate']['max_items'] ?? null, config('api-tools.max_items',100), "steps.$i.iterate.max_items");
            }
        }
        $dependencies = [];
        foreach ($steps as $i => $step) {
            $id = $step['id'];
            $deps = $step['wait_for'] ?? [];
            $this->check(is_array($deps) && array_is_list($deps), "steps.$i.wait_for", 'Waits must be a list of step identifiers.');
            foreach ($deps as $dependency) { $this->check(is_string($dependency) && isset($ids[$dependency]) && $dependency !== $id, "steps.$i.wait_for", 'Wait references an unknown or the same step.'); }
            $bindings = $step['bindings'] ?? [];
            $this->check(is_array($bindings), "steps.$i.bindings", 'Bindings must be a parameter map.');
            $route = ($step['operation'] ?? 'api') === 'api' ? $routes[$step['route_id']] : null;
            $parameters = $route?->parameters->where('source', \Padosoft\AskMyDocsConnectorApi\Support\ParamSource::Llm)->keyBy('name');
            foreach ($bindings as $name => $binding) {
                $this->check(is_array($binding), "steps.$i.bindings.$name", 'Invalid binding.');
                if ($route) {
                    $this->check($parameters?->has($name) === true, "steps.$i.bindings.$name", 'Unknown public endpoint parameter; authentication belongs to the connector.');
                    $this->check(! ConnectorSecretGuard::sensitive((string) $name), "steps.$i.bindings.$name", 'Authentication cannot be mapped by a tool.');
                }
                $sourceSchema = $this->binding($binding, $definition['input_schema'], $schemas, $ids, $id, "steps.$i.bindings.$name", isset($step['iterate']));
                $target = $route ? ($this->publicInputSchema($route)['properties'][$name] ?? []) : [];
                $this->compatible($sourceSchema, $target, "steps.$i.bindings.$name");
                if (($binding['source'] ?? '') === 'constant' && $target !== []) { $this->assertValue($binding['value'] ?? null, $target, "steps.$i.bindings.$name"); }
                if (($binding['source'] ?? '') === 'step') { $deps[] = $binding['step']; }
            }
            if ($route) {
                $required = $this->publicInputSchema($route)['required'];
                foreach ($required as $name) { $this->check(array_key_exists($name, $bindings), "steps.$i.bindings.$name", 'Required endpoint input needs an explicit source.'); }
            }
            if (isset($step['iterate'])) {
                $binding = $step['iterate']['source'] ?? [];
                $schema = $this->binding($binding, $definition['input_schema'], $schemas, $ids, $id, "steps.$i.iterate.source", false);
                $this->check(! isset($schema['type']) || $schema['type'] === 'array', "steps.$i.iterate.source", 'Iteration requires a list.');
                if (($binding['source'] ?? '') === 'step') { $deps[] = $binding['step']; }
            }
            $dependencies[$id] = array_values(array_unique($deps));
            $definition['steps'][$i]['operation'] = $step['operation'] ?? 'api';
            $definition['steps'][$i]['wait_for'] = array_values(array_unique($step['wait_for'] ?? []));
        }
        $remaining = $dependencies;
        while ($remaining !== []) {
            $ready = array_keys(array_filter($remaining, fn ($deps) => array_intersect($deps, array_keys($remaining)) === []));
            $this->check($ready !== [], 'steps', 'Mappings and waits contain a cycle.');
            foreach ($ready as $id) { unset($remaining[$id]); }
        }
        $output = $definition['output'] ?? [];
        $this->check(is_array($output) && $output !== [], 'output', 'Select at least one explicit public output.');
        foreach ($output as $key => $binding) { $this->binding($binding, $definition['input_schema'], $schemas, $ids, null, "output.$key", false); }
        $definition['tests'] ??= [];
        foreach ($definition['tests'] as $i => $test) {
            $this->check(in_array($test['mode'] ?? '', ['simulation','live'], true), "tests.$i.mode", 'Tests must declare simulation or live.');
            $this->assertValue($test['inputs'] ?? [], $definition['input_schema'], "tests.$i.inputs");
            foreach ($test['assertions'] ?? [] as $assertion) {
                $this->check(in_array($assertion['operator'] ?? '', ['exists','type','equals','count','min_count','max_count'], true), "tests.$i.assertions", 'Unsupported assertion.');
            }
            $this->check(! empty($test['assertions']), "tests.$i.assertions", 'Each test needs at least one assertion.');
        }
        $definition['limits'] ??= [];
        $definition['limits']['physical_requests'] ??= 30;
        $definition['limits']['timeout_seconds'] ??= (int) config('api-tools.timeout_seconds',120);
        $this->bounded($definition['limits']['physical_requests'], config('api-tools.max_physical_requests',100), 'limits.physical_requests');
        $this->bounded($definition['limits']['timeout_seconds'], 600, 'limits.timeout_seconds');
        $this->secrets->assertClean($definition, $routes);
        return $definition;
    }

    public function publicInputSchema(\Padosoft\AskMyDocsConnectorApi\Models\ApiRoute $route): array
    {
        $route->loadMissing('parameters');
        $schema = is_array($route->input_schema) ? $route->input_schema : ['type'=>'object'];
        $properties = []; $required = [];
        foreach ($route->parameters as $parameter) {
            if ($parameter->source !== \Padosoft\AskMyDocsConnectorApi\Support\ParamSource::Llm) { continue; }
            $properties[$parameter->name] = array_merge($schema['properties'][$parameter->name] ?? [], ['type'=>$parameter->type->value]);
            if ($parameter->required) { $required[] = $parameter->name; }
        }
        $schema['type'] = 'object';
        $schema['properties'] = $properties;
        $schema['required'] = array_values(array_unique([...$required,...array_intersect($schema['required'] ?? [],array_keys($properties))]));
        $schema['additionalProperties'] = false;
        return $schema;
    }

    public function dependencies(array $step): array
    {
        $deps = $step['wait_for'] ?? [];
        foreach ([...array_values($step['bindings'] ?? []), $step['iterate']['source'] ?? []] as $binding) {
            if (($binding['source'] ?? '') === 'step') { $deps[] = $binding['step']; }
        }
        return array_values(array_unique($deps));
    }

    public function assertValue(mixed $value, array $schema, string $path = 'inputs'): void
    {
        $encoded = json_encode($value === [] && ($schema['type'] ?? '') === 'object' ? (object) [] : $value, JSON_THROW_ON_ERROR);
        $jsonSchema = json_decode(json_encode($schema, JSON_THROW_ON_ERROR));
        // PHP empty property maps serialize as lists; JSON Schema requires objects.
        if (isset($jsonSchema->properties) && $jsonSchema->properties === []) { $jsonSchema->properties = (object) []; }
        try { $valid = (new Validator)->validate(json_decode($encoded), $jsonSchema)->isValid(); }
        catch (\Throwable) { $valid = false; }
        $this->check($valid, $path, 'Value does not match its declared JSON schema.');
    }

    private function binding(array $b, array $inputs, array $schemas, array $ids, ?string $current, string $path, bool $allowItem): array
    {
        $source = $b['source'] ?? '';
        $this->check(in_array($source, $allowItem ? ['input','constant','step','item'] : ['input','constant','step'], true), $path, 'Select a valid source.');
        $this->check(is_string($b['path'] ?? '') && ! str_contains($b['path'] ?? '', '*'), $path, 'Use explicit selection or projection for a list.');
        $schema = [];
        if ($source === 'step') {
            $this->check(is_string($b['step'] ?? null) && isset($ids[$b['step']]) && $b['step'] !== $current, $path, 'Source step is unknown or refers to itself.');
            $schema = $schemas[$b['step']];
        } elseif ($source === 'input') { $schema = $inputs; }
        if (in_array($source, ['input','step'], true)) { $schema = $this->schemaPath($schema, $b['path'] ?? '', $path); }
        $mode = $b['mode'] ?? 'value';
        $this->check(in_array($mode, ['value','index','project'], true), $path, 'Unknown list mapping.');
        if ($mode !== 'value') {
            $this->check(! isset($schema['type']) || $schema['type'] === 'array', $path, 'Selection or projection requires a list.');
            if ($mode === 'index') { $this->check(is_int($b['index'] ?? null) && $b['index'] >= 0, $path, 'Choose an explicit non-negative index.'); }
            $item = $this->schemaPath($schema['items'] ?? [], $b['project_path'] ?? '', $path);
            $schema = $mode === 'project' ? ['type'=>'array','items'=>$item] : $item;
        }
        return $schema;
    }

    private function schemaPath(array $schema, string $path, string $errorPath): array
    {
        if ($path === '') { return $schema; }
        foreach (explode('.', $path) as $part) {
            if ($schema === []) { return []; }
            $this->check(($schema['type'] ?? '') !== 'array', $errorPath, 'Lists need explicit index or projection; no first-item conversion.');
            $this->check(isset($schema['properties'][$part]), $errorPath, "Unknown schema field [$part].");
            $schema = $schema['properties'][$part];
        }
        return $schema;
    }

    private function compatible(array $from, array $to, string $path): void
    {
        if (! isset($from['type'], $to['type'])) { return; }
        $this->check($from['type'] === $to['type'] || ($from['type'] === 'integer' && $to['type'] === 'number'), $path, 'Source type/cardinality is incompatible with this endpoint input.');
    }
    private function bounded(mixed $n, int $max, string $path): void { $this->check(is_int($n) && $n > 0 && $n <= $max, $path, "Choose a limit between 1 and $max."); }
    private function check(bool $valid, string $path, string $message): void { if (! $valid) { throw ValidationException::withMessages([$path => $message]); } }
}
