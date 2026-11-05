<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Support\SensitivePayloadRedactor;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Support\RequestPlanner;

/** A snapshot of the initial request, without resolving any credentials. */
final readonly class ApiSourceRequest
{
    public function snapshot(ApiRoute $route, array $arguments): ?array
    {
        try {
            $route = clone $route;
            // Never load auth material. Secret parameters keep their position,
            // but their values are replaced before the normal planner runs.
            $route->setRelation('parameters', $route->parameters->map(function ($parameter) {
                $copy = clone $parameter;
                if ($copy->source === ParamSource::Secret) {
                    $copy->source = ParamSource::Fixed;
                    $copy->type = ParamType::String;
                    $copy->value = '[REDACTED]';
                }
                return $copy;
            }));
            $route->setRelation('connector', null); // Shared/auth headers are deliberately omitted.
            $plan = app(RequestPlanner::class)->plan($route, $arguments, null);

            return app(SensitivePayloadRedactor::class)->redact([
                'method' => $plan->method->value,
                'url' => $plan->url,
                'query' => $plan->query,
                'headers' => array_intersect_key($plan->headers, array_flip(['accept', 'accept-language', 'content-type'])),
                'body' => $plan->body,
            ]);
        } catch (\Throwable) {
            // Missing diagnostic information must not interrupt retrieval.
            return null;
        }
    }
}
