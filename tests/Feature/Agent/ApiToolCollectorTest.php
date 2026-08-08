<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\Budget\AgentBudgetTracker;
use App\Agent\Tools\ApiToolCollector;
use App\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteRelation;
use Tests\TestCase;

final class ApiToolCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('connector-api.ssrf.enabled', true);
        config()->set('connector-api.ssrf.https_only', false);
        config()->set('connector-api.ssrf.resolve_dns', false);
        config()->set('agent.tools.pagination_max_pages', 10);
        config()->set('agent.tools.fanout_concurrency', 5);
    }

    public function test_page_collection_is_one_logical_action_with_bounded_physical_requests(): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request;
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ((int) ($query['page'] ?? 0)) {
                1 => Http::response(['data' => [['id' => 1], ['id' => 2]]]),
                2 => Http::response(['data' => [['id' => 3]]]),
                default => Http::response(['error' => 'unexpected page'], 500),
            };
        });

        $route = $this->route('orders', 'http://api.example.test/orders', [
            'type' => 'page',
            'page_param' => 'page',
            'size_param' => 'per_page',
            'start_page' => 1,
            'items_path' => 'data',
        ], endpointType: 'list', itemsPath: 'data');
        $this->parameter($route, 'per_page', 'query', 'fixed', 'integer', '2');

        $budget = $this->budget();
        $result = app(ApiToolCollector::class)->collect(
            $route->fresh()->load('parameters'),
            [],
            $this->context(),
            $budget,
        );

        $this->assertTrue($result->complete);
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $result->body['items']);
        $this->assertSame(2, $result->physicalRequests);
        $this->assertSame(2, $budget->snapshot()['physical_calls']);
        $this->assertSame('it-IT', $requests[0]->header('Accept-Language')[0] ?? null);
        $this->assertStringContainsString('page=2', $requests[1]->url());
    }

    public function test_cursor_collection_stops_on_end_token_and_rejects_cross_origin_next_urls(): void
    {
        Http::fakeSequence('api.example.test/*')
            ->push(['data' => [['id' => 1]], 'meta' => ['next' => 'abc']])
            ->push(['data' => [['id' => 2]], 'meta' => ['next' => null]]);

        $route = $this->route('cursor-orders', 'http://api.example.test/orders', [
            'type' => 'cursor',
            'cursor_param' => 'cursor',
            'next_cursor_path' => 'meta.next',
            'items_path' => 'data',
        ], endpointType: 'list', itemsPath: 'data');

        $result = app(ApiToolCollector::class)->collect($route, [], $this->context(), $this->budget());

        $this->assertTrue($result->complete);
        $this->assertSame([['id' => 1], ['id' => 2]], $result->body['items']);
        $this->assertSame(2, $result->physicalRequests);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'cursor=abc'));

        Http::fake(['safe.example.test/*' => Http::response([
            'data' => [['id' => 1]],
            'links' => ['next' => 'http://evil.example.test/exfiltrate'],
        ])]);
        $urlRoute = $this->route('url-orders', 'http://safe.example.test/orders', [
            'type' => 'cursor',
            'next_url_path' => 'links.next',
            'items_path' => 'data',
        ], endpointType: 'list', itemsPath: 'data');

        $unsafe = app(ApiToolCollector::class)->collect($urlRoute, [], $this->context(), $this->budget());

        $this->assertFalse($unsafe->complete);
        $this->assertSame('unsafe_next_url', $unsafe->stopReason);
        $this->assertSame(1, $unsafe->physicalRequests);
    }

    public function test_read_only_relation_fanout_maps_items_and_never_exceeds_five_in_a_batch(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'id' => (int) basename(parse_url($request->url(), PHP_URL_PATH)),
            'status' => 'paid',
        ]));

        $list = $this->route('customers', 'http://api.example.test/customers', null, endpointType: 'list', itemsPath: 'data');
        $detail = $this->route('customer-detail', 'http://api.example.test/customers/{id}', null, endpointType: 'detail');
        $this->parameter($detail, 'id', 'path', 'llm', 'integer', null, true);
        $relation = ApiRouteRelation::create([
            'tenant_id' => 'acme',
            'api_connector_id' => $list->api_connector_id,
            'list_route_id' => $list->id,
            'detail_route_id' => $detail->id,
            'field_map' => [['from' => 'customer_id', 'to_param' => 'id', 'to_location' => 'path']],
        ]);
        $progress = [];

        $result = app(ApiToolCollector::class)->collectRelatedDetails(
            $relation,
            [
                ['customer_id' => 10],
                ['customer_id' => 11],
                ['customer_id' => 12],
                ['customer_id' => 13],
                ['customer_id' => 14],
                ['customer_id' => 15],
            ],
            $this->context(),
            $this->budget(),
            static function (array $event) use (&$progress): void {
                $progress[] = $event;
            },
        );

        $this->assertTrue($result->complete);
        $this->assertSame(6, $result->physicalRequests);
        $this->assertSame(5, $result->stats['concurrency']);
        $this->assertCount(6, $result->body['items']);
        $this->assertSame([5, 6], array_column($progress, 'completed'));
        Http::assertSentCount(6);
    }

    public function test_fanout_refuses_mutating_or_cross_scope_detail_routes(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $list = $this->route('list', 'http://api.example.test/items', null, endpointType: 'list');
        $detail = $this->route('mutating-detail', 'http://api.example.test/items', null, endpointType: 'detail', method: 'POST');
        $relation = ApiRouteRelation::create([
            'tenant_id' => 'acme',
            'api_connector_id' => $list->api_connector_id,
            'list_route_id' => $list->id,
            'detail_route_id' => $detail->id,
            'field_map' => [],
        ]);

        $result = app(ApiToolCollector::class)->collectRelatedDetails(
            $relation,
            [['id' => 1]],
            $this->context(),
            $this->budget(),
        );

        $this->assertFalse($result->complete);
        $this->assertSame('fanout_requires_read_only_route', $result->stopReason);
        Http::assertNothingSent();
    }

    /** @param array<string,mixed>|null $pagination */
    private function route(
        string $slug,
        string $url,
        ?array $pagination,
        string $endpointType = 'unknown',
        ?string $itemsPath = null,
        string $method = 'GET',
    ): ApiRoute {
        $connector = ApiConnector::create([
            'tenant_id' => 'acme',
            'project_key' => 'crm',
            'name' => 'ERP '.$slug,
            'is_active' => true,
        ]);

        return ApiRoute::create([
            'tenant_id' => 'acme',
            'api_connector_id' => $connector->id,
            'project_key' => 'crm',
            'name' => $slug,
            'slug' => $slug,
            'http_method' => $method,
            'url' => $url,
            'mode' => 'tool',
            'status' => 'active',
            'endpoint_type' => $endpointType,
            'items_path' => $itemsPath,
            'pagination' => $pagination,
            'tool_definition' => [
                'name' => $slug,
                'description' => $slug,
                'input_schema' => ['type' => 'object', 'properties' => []],
            ],
        ])->load('parameters');
    }

    private function parameter(
        ApiRoute $route,
        string $name,
        string $location,
        string $source,
        string $type,
        ?string $value,
        bool $required = false,
    ): void {
        ApiRouteParameter::create([
            'tenant_id' => 'acme',
            'api_route_id' => $route->id,
            'name' => $name,
            'location' => $location,
            'source' => $source,
            'type' => $type,
            'required' => $required,
            'value' => $value,
        ]);
    }

    private function context(): AgentExecutionContext
    {
        return new AgentExecutionContext(
            runId: Str::uuid()->toString(),
            tenantId: 'acme',
            projectKey: 'crm',
            channel: 'chat',
            actorType: 'user',
            actorId: '1',
            locale: 'it-IT',
            timezone: 'Europe/Rome',
        );
    }

    private function budget(): AgentBudgetTracker
    {
        return new AgentBudgetTracker(AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'acme',
            'project_key' => 'crm',
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => '1',
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING,
            'started_at' => now(),
            'budget_json' => [],
            'counters_json' => [],
        ]));
    }
}
