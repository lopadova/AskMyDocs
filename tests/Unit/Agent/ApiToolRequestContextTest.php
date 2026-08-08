<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Tools\ApiToolRequestContext;
use Illuminate\Database\Eloquent\Collection;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Tests\TestCase;

final class ApiToolRequestContextTest extends TestCase
{
    public function test_it_injects_locale_without_exposing_it_as_an_llm_parameter(): void
    {
        $route = new ApiRoute([
            'tenant_id' => 'acme',
            'http_method' => HttpMethod::GET->value,
            'url' => 'https://api.example.test/orders',
        ]);
        $route->id = 7;
        $route->setRelation('parameters', new Collection);

        $prepared = app(ApiToolRequestContext::class)->apply($route, ['customer_id' => '42'], ['locale' => 'it-IT']);

        $localeParameter = $prepared['route']->parameters->firstWhere('name', 'Accept-Language');
        $this->assertSame('it-IT', $localeParameter?->value);
        $this->assertSame('it-IT', $prepared['arguments']['_agent_locale']);
        $this->assertSame('42', $prepared['arguments']['customer_id']);
    }

    public function test_it_resolves_context_placeholders_in_fixed_parameters(): void
    {
        $route = new ApiRoute([
            'tenant_id' => 'acme',
            'http_method' => HttpMethod::GET->value,
            'url' => 'https://api.example.test/orders',
        ]);
        $route->id = 7;
        $route->setRelation('parameters', new Collection([
            new ApiRouteParameter([
                'name' => 'locale_code',
                'location' => 'query',
                'source' => 'fixed',
                'type' => 'string',
                'value' => '{{context.language}}-{{context.locale}}',
            ]),
        ]));

        $prepared = app(ApiToolRequestContext::class)->apply($route, [], ['locale' => 'en-US']);

        $this->assertSame('en-en-US', $prepared['route']->parameters->firstWhere('name', 'locale_code')?->value);
    }
}
