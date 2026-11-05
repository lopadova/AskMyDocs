<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Services\ApiRouteTester;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;
use Tests\TestCase;

final class ApiConnectorStructuredResponsesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['connector-api.ssrf.resolve_dns' => false, 'connector-api.defaults.retry_times' => 0]);
    }

    public function test_get_type_json_and_opt_in_xml_have_identical_test_and_runtime_results(): void
    {
        $route = $this->route();
        Http::fake(['api.example.com/*' => Http::response('<resi><reso><reso_ID>12</reso_ID><reso_codice>RU12</reso_codice><ord_num_doc>U12345</ord_num_doc><reso_prezzotot>215.00</reso_prezzotot></reso></resi>', 200, ['Content-Type' => 'text/xml'])]);
        $test = app(ApiRouteTester::class)->dryRun($route);
        $runtime = app(ApiToolExecutor::class)->execute($route, []);
        $this->assertTrue($test->ok);
        $this->assertSame($test->body, $runtime);
        $this->assertSame('RU12', $runtime['resi']['reso'][0]['reso_codice']);
        $this->assertSame('215.00', $runtime['resi']['reso'][0]['reso_prezzotot']);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('json', $request['type']);
        }
    }

    public function test_xml_error_with_http_200_cannot_be_mistaken_for_a_successful_empty_list(): void
    {
        Http::fake(['api.example.com/*' => Http::response('<response><ack>KO</ack><message>Invalid filter</message></response>', 200)]);
        $route = $this->route();
        $this->assertFalse(app(ApiRouteTester::class)->dryRun($route)->ok);
        $result = app(ApiToolExecutor::class)->execute($route, []);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(200, $result['status']);
    }

    public function test_gescat_parameter_error_has_the_same_explanation_in_endpoint_tests_and_runtime(): void
    {
        $xml = '<result><ack>KO</ack><error_message><![CDATA[Il parametro "orderBy" impone l\'Ordinamento dati.]]></error_message></result>';
        Http::fake(['api.example.com/*' => Http::response($xml, 200, ['Content-Type' => 'text/xml'])]);
        $route = $this->route();
        $route->update(['output_transform' => null]); // Successful responses require JSON; errors may be XML.
        $test = app(ApiRouteTester::class)->dryRun($route);
        $runtime = app(ApiToolExecutor::class)->execute($route, []);
        $this->assertFalse($test->ok);
        $this->assertSame($runtime['error'], $test->error);
        $this->assertSame('api_parameter_rejected', $runtime['error_code']);
        $this->assertSame('orderBy', $runtime['invalid_parameter']);
        $this->assertNull($route->fresh()->last_test_at);
        $this->assertSame(200, $runtime['status']);
        $this->assertStringNotContainsString('Ordinamento dati', json_encode($runtime));
    }

    public function test_xml_remains_unsupported_on_routes_without_explicit_opt_in(): void
    {
        Http::fake(['api.example.com/*' => Http::response('<resi/>', 200)]);
        $route = $this->route();
        $route->update(['output_transform' => null]);
        $this->assertFalse(app(ApiRouteTester::class)->dryRun($route)->ok);
        $this->assertArrayHasKey('error', app(ApiToolExecutor::class)->execute($route, []));
    }

    public function test_json_responses_still_use_existing_field_selection_and_http_failures_stay_errors(): void
    {
        $route = $this->route();
        $route->update(['output_transform' => ['include' => ['order.*.id']]]);
        Http::fakeSequence('api.example.com/*')->push(['order' => [['id' => 1, 'payment_secret' => 'hidden']]])->push(['error' => 'denied'], 403);
        $this->assertSame(['order' => [['id' => 1]]], app(ApiToolExecutor::class)->execute($route, []));
        $this->assertSame(403, app(ApiToolExecutor::class)->execute($route, [])['status']);
    }

    private function route(): ApiRoute
    {
        $connector = ApiConnector::create(['tenant_id' => 'default', 'project_key' => '', 'name' => 'Read API', 'base_url' => 'https://api.example.com', 'is_active' => true]);
        $route = ApiRoute::create(['tenant_id' => 'default', 'api_connector_id' => $connector->id, 'project_key' => '',
            'name' => 'Returns', 'slug' => 'returns', 'http_method' => 'GET', 'url' => 'https://api.example.com/returns',
            'status' => 'active', 'mode' => 'tool', 'output_transform' => ['response_format' => 'xml', 'xml_root' => 'resi', 'xml_list_paths' => ['resi.reso']]]);
        ApiRouteParameter::create(['tenant_id' => 'default', 'api_route_id' => $route->id, 'name' => 'type',
            'location' => 'query', 'source' => 'fixed', 'type' => 'string', 'required' => true, 'value' => 'json']);
        return $route->load('parameters');
    }
}
