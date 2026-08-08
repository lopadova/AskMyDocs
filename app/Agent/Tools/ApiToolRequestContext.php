<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Support\SupportedLocale;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;

/** Add non-LLM execution context to a transient API route request plan. */
final class ApiToolRequestContext
{
    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $context
     * @return array{route:ApiRoute,arguments:array<string,mixed>,context:array<string,mixed>}
     */
    public function apply(ApiRoute $route, array $arguments, array $context): array
    {
        $locale = SupportedLocale::normalize(is_string($context['locale'] ?? null) ? $context['locale'] : null);
        $route->loadMissing('parameters');

        $parameters = $route->parameters->map(function (ApiRouteParameter $parameter) use ($locale): ApiRouteParameter {
            if ($parameter->source !== ParamSource::Fixed || ! is_string($parameter->value)) {
                return $parameter;
            }

            $copy = clone $parameter;
            $copy->value = str_replace(
                ['{{context.locale}}', '{{context.language}}'],
                [$locale, strtolower(strtok($locale, '-') ?: $locale)],
                $parameter->value,
            );

            return $copy;
        })->reject(fn (ApiRouteParameter $parameter): bool => $parameter->location === ParamLocation::Header
            && strcasecmp($parameter->name, 'Accept-Language') === 0);

        $parameters->push(new ApiRouteParameter([
            'tenant_id' => $route->tenant_id,
            'api_route_id' => $route->id,
            'name' => 'Accept-Language',
            'location' => ParamLocation::Header->value,
            'source' => ParamSource::Fixed->value,
            'type' => ParamType::String->value,
            'required' => false,
            'value' => $locale,
            'description' => 'Immutable agent-run locale.',
            'sort_order' => PHP_INT_MAX,
        ]));
        $route->setRelation('parameters', $parameters->values());

        // Unknown arguments are ignored by RequestPlanner but participate in
        // the package cache key, preventing cross-locale response reuse.
        $arguments['_agent_locale'] = $locale;
        $context['locale'] = $locale;

        return compact('route', 'arguments', 'context');
    }
}
