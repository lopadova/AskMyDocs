<?php

declare(strict_types=1);

namespace App\ApiTools;

use App\Agent\AgentExecutionContext;
use App\ApiTools\Models\ApiTool;
use Illuminate\Validation\ValidationException;

/** Portable references resolve to existing local dependencies; credentials never travel. */
final class ToolPackageService
{
    public const FORMAT = 'askmydocs.api-tool';
    public const VERSION = 1;

    public function __construct(private readonly ToolAccess $access, private readonly ConnectorSecretGuard $secrets, private readonly ToolLifecycle $lifecycle) {}

    public function export(ApiTool $tool): array
    {
        $definition = array_intersect_key($tool->revision->definition, array_flip(['version', 'input_schema', 'steps', 'output', 'tests', 'limits']));
        $routes = $this->access->routes($tool->tenant_id, $tool->project_key)->keyBy('id');
        $dependencies = [];
        foreach ($definition['steps'] ?? [] as $index => $step) {
            if (($step['operation'] ?? 'api') !== 'api') { continue; }
            $route = $routes->get($step['route_id'] ?? 0);
            if (! $route) { throw ValidationException::withMessages(['dependencies' => 'A referenced endpoint is unavailable in this tool scope.']); }
            $ref = 'endpoint-'.(count($dependencies) + 1);
            foreach ($dependencies as $existing) {
                if ($existing['connector'] === $route->connector->name && $existing['slug'] === $route->slug && $existing['method'] === $route->http_method->value) { $ref = $existing['ref']; break; }
            }
            $dependencies[$ref] = ['ref' => $ref, 'connector' => $route->connector->name, 'slug' => $route->slug, 'method' => $route->http_method->value];
            unset($definition['steps'][$index]['route_id']);
            $definition['steps'][$index]['route_ref'] = $ref;
        }
        $package = [
            '_meta' => ['format' => self::FORMAT, 'version' => self::VERSION],
            'tool' => ['name' => $tool->name, 'description' => $tool->description, 'failure_threshold' => $tool->failure_threshold, 'definition' => $definition],
            'dependencies' => array_values($dependencies),
        ];
        $this->secrets->assertClean($package, $routes);
        return $package;
    }

    public function import(array $package, string $project, AgentExecutionContext $context): ApiTool
    {
        $this->access->assertActor($context);
        if ($project !== ($context->projectKey ?? '')) { throw new \DomainException('api_tool_scope_mismatch'); }
        if (($package['_meta']['format'] ?? null) !== self::FORMAT || ($package['_meta']['version'] ?? null) !== self::VERSION
            || ! is_array($package['tool']['definition'] ?? null) || ! is_array($package['dependencies'] ?? null)) {
            throw ValidationException::withMessages(['package' => 'Unsupported API tool package.']);
        }
        $routes = $this->access->routes($context->tenantId, $project);
        $resolved = [];
        foreach ($package['dependencies'] as $dependency) {
            if (! is_array($dependency) || ! is_string($dependency['ref'] ?? null) || isset($resolved[$dependency['ref']])
                || ! is_string($dependency['connector'] ?? null) || ! is_string($dependency['slug'] ?? null)) {
                throw ValidationException::withMessages(['dependencies' => 'Dependency references must be unique and portable.']);
            }
            $matches = $routes->filter(fn ($route) => $route->connector?->name === $dependency['connector']
                && $route->slug === $dependency['slug'] && $route->http_method->value === ($dependency['method'] ?? 'GET'));
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['dependencies' => 'Each connector and endpoint reference must resolve uniquely in the selected tenant and project.']);
            }
            $resolved[$dependency['ref']] = $matches->first()->id;
        }
        $definition = $package['tool']['definition'];
        foreach ($definition['steps'] ?? [] as $index => $step) {
            if (($step['operation'] ?? 'api') !== 'api') { continue; }
            if (isset($step['route_id']) || ! isset($resolved[$step['route_ref'] ?? ''])) {
                throw ValidationException::withMessages(['dependencies' => 'Endpoint IDs cannot be imported; use a resolved portable reference.']);
            }
            $definition['steps'][$index]['route_id'] = $resolved[$step['route_ref']];
            unset($definition['steps'][$index]['route_ref']);
        }
        $this->secrets->assertClean($package, $routes);
        $name = trim((string) ($package['tool']['name'] ?? ''));
        $base = mb_substr($name, 0, 230);
        for ($suffix = 2; ApiTool::query()->forTenant($context->tenantId)->where('project_key', $project)->where('name', $name)->exists(); $suffix++) {
            $name = $base.' ('.$suffix.')';
        }
        return $this->lifecycle->saveDraft(null, ['name' => $name, 'description' => $package['tool']['description'] ?? null, 'failure_threshold' => $package['tool']['failure_threshold'] ?? config('api-tools.failure_threshold', 3), 'definition' => $definition], $context);
    }
}
