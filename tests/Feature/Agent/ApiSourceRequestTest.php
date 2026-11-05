<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\Tools\ApiToolRequestContext;
use App\Agent\Tools\ApiSourceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Tests\TestCase;

final class ApiSourceRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_initial_request_uses_runtime_arguments_and_locale_without_resolving_secrets(): void
    {
        Http::preventStrayRequests();
        $connector = ApiConnector::create(['tenant_id' => 'test-tenant', 'name' => 'ERP', 'is_active' => true, 'headers' => ['X-Custom-Auth' => 'never-show']]);
        $route = ApiRoute::create(['tenant_id' => 'test-tenant', 'api_connector_id' => $connector->id, 'name' => 'Order', 'slug' => 'order',
            'http_method' => 'GET', 'url' => 'https://erp.example.test/orders/{id}', 'mode' => 'tool', 'status' => 'active']);
        foreach ([['id', 'path', 'llm', null], ['ord_codiceweb', 'query', 'llm', null], ['credential', 'query', 'secret', null],
            ['X-Private-Header', 'header', 'fixed', 'never-show'], ['language', 'query', 'fixed', '{{context.language}}']] as [$name, $location, $source, $value]) {
            $route->parameters()->create(['tenant_id' => 'test-tenant', 'name' => $name, 'location' => $location, 'source' => $source,
                'type' => 'string', 'required' => true, 'value' => $value, 'secret_ref' => $source === 'secret' ? 'credential' : null]);
        }
        $context = new AgentExecutionContext('probe', 'test-tenant', 'orders', 'chat', 'user', '1', 'it-IT', 'Europe/Rome');
        $prepared = app(ApiToolRequestContext::class)->apply($route, ['id' => 'A/B', 'ord_codiceweb' => 'ORDER-A'], $context->jsonSerialize());
        $snapshot = app(ApiSourceRequest::class)->snapshot($prepared['route'], $prepared['arguments']);
        $this->assertSame('GET', $snapshot['method']);
        $this->assertSame('https://erp.example.test/orders/A%2FB', $snapshot['url']);
        $this->assertSame('ORDER-A', $snapshot['query']['ord_codiceweb']);
        $this->assertSame('[REDACTED]', $snapshot['query']['credential']);
        $this->assertSame('it', $snapshot['query']['language']);
        $this->assertSame(['accept-language' => 'it-IT'], $snapshot['headers']);
        $this->assertStringNotContainsString('never-show', json_encode($snapshot));
        // Later endpoint changes cannot alter the already captured snapshot.
        $route->update(['url' => 'https://erp.example.test/new']);
        $this->assertSame('https://erp.example.test/orders/A%2FB', $snapshot['url']);
        Http::assertNothingSent();
    }
}
