<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoop;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Tests\TestCase;

final class AgentLoopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('connector-api.ssrf.enabled', true);
        config()->set('connector-api.ssrf.https_only', false);
        config()->set('connector-api.ssrf.resolve_dns', false);
    }

    public function test_it_chains_customer_lookup_into_orders_and_replans_with_combined_evidence(): void
    {
        $findCustomer = $this->route('find_customer', 'http://erp.example.test/customers');
        $this->parameter($findCustomer, 'name', 'query');
        $getOrders = $this->route('get_orders', 'http://erp.example.test/customers/{customer_id}/orders');
        $this->parameter($getOrders, 'customer_id', 'path', 'integer');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/customers?')) {
                return Http::response(['items' => [['id' => 77, 'name' => 'Tizio']]]);
            }

            return Http::response(['orders' => [
                ['number' => 'A-100', 'total' => 120],
                ['number' => 'A-101', 'total' => 80],
            ]]);
        });

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            $this->planResponse([
                'decision' => 'tools',
                'actions' => [
                    [
                        'id' => 'find_customer',
                        'tool' => 'find_customer',
                        'arguments' => ['name' => 'Tizio'],
                        'depends_on' => [],
                        'purpose' => 'Cerco il cliente richiesto',
                    ],
                    [
                        'id' => 'load_orders',
                        'tool' => 'get_orders',
                        'arguments' => [
                            'customer_id' => ['$from' => 'find_customer', 'path' => 'items.0.id'],
                        ],
                        'depends_on' => ['find_customer'],
                        'purpose' => 'Recupero gli ordini del cliente',
                    ],
                ],
            ]),
            $this->planResponse(['decision' => 'answer', 'actions' => []]),
        );
        $this->app->instance(AiManager::class, $ai);

        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->with(
            'Dammi tutti gli ordini di Tizio',
            'crm',
            Mockery::type(\App\Services\Kb\Retrieval\RetrievalFilters::class),
        )
            ->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $run = $this->makeRun();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('answer', $outcome->decision);
        $this->assertCount(2, $outcome->evidence->apiTools());
        $this->assertSame(['completed', 'completed'], array_column($outcome->completedActions, 'status'));
        $this->assertSame(77, $run->toolExecutions()->orderBy('logical_index')->get()[1]->arguments_json['customer_id']);
        $this->assertSame(2, $run->fresh()->counters_json['physical_calls']);
        $startedEvent = $run->events()->where('type', 'tool.started')->first();
        $this->assertSame('it-IT', $startedEvent->locale);
        $this->assertSame('api', data_get($startedEvent->payload_json, 'data.tool_kind'));
        $this->assertSame('find_customer', data_get($startedEvent->payload_json, 'data.tool_display_name'));
        $this->assertSame(2, $run->toolExecutions()->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/customers/77/orders'));
    }

    public function test_failed_dependency_is_skipped_without_calling_the_downstream_api(): void
    {
        $detail = $this->route('get_orders', 'http://erp.example.test/customers/{customer_id}/orders');
        $this->parameter($detail, 'customer_id', 'path', 'integer');
        Http::fake(['*' => Http::response(['should_not' => 'run'])]);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            $this->planResponse([
                'decision' => 'tools',
                'actions' => [[
                    'id' => 'orders',
                    'tool' => 'get_orders',
                    'arguments' => ['customer_id' => ['$from' => 'missing', 'path' => 'items.0.id']],
                    'depends_on' => [],
                    'purpose' => 'Recupero gli ordini',
                ]],
            ]),
            $this->planResponse(['decision' => 'insufficient', 'actions' => []]),
        );
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $run = $this->makeRun();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('insufficient', $outcome->decision);
        $this->assertSame('skipped', $run->toolExecutions()->sole()->status);
        $this->assertSame('dependency_resolution_failed', $run->toolExecutions()->sole()->error_code);
        Http::assertNothingSent();
    }

    public function test_it_stops_before_using_the_first_of_multiple_customers_for_orders(): void
    {
        $findCustomer = $this->route('find_customer', 'http://erp.example.test/customers');
        $this->parameter($findCustomer, 'name', 'query');
        $getOrders = $this->route('get_orders', 'http://erp.example.test/customers/{customer_id}/orders');
        $this->parameter($getOrders, 'customer_id', 'path', 'integer');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/customers?')) {
                return Http::response(['items' => [
                    ['id' => 147768, 'name' => 'Riccardo Lorini'],
                    ['id' => 147767, 'name' => 'Riccardo Lorini'],
                ]]);
            }

            return Http::response(['orders' => [['number' => 'must-not-be-loaded']]]);
        });

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            $this->planResponse([
                'decision' => 'tools',
                'actions' => [[
                    'id' => 'find_customer',
                    'tool' => 'find_customer',
                    'arguments' => ['name' => 'Riccardo Lorini'],
                    'depends_on' => [],
                    'purpose' => 'Cerco il cliente richiesto',
                ]],
            ]),
            $this->planResponse([
                'decision' => 'tools',
                'actions' => [[
                    'id' => 'load_orders',
                    'tool' => 'get_orders',
                    'arguments' => ['customer_id' => 147768],
                    'depends_on' => [],
                    'purpose' => 'Recupero gli ordini del cliente',
                ]],
            ]),
        );
        $this->app->instance(AiManager::class, $ai);

        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $run = $this->makeRun();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('answer', $outcome->decision);
        $this->assertSame('ambiguous_selection_required', $outcome->stopReason);
        $this->assertCount(1, $outcome->evidence->apiTools());
        $this->assertSame('ambiguous_selection_required', data_get($outcome->evidence->jsonSerialize(), 'warnings.0.code'));
        $this->assertSame(1, $run->toolExecutions()->count());
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/orders'));
    }

    public function test_current_selection_is_a_resolvable_dependency_source(): void
    {
        $getOrders = $this->route('get_orders', 'http://erp.example.test/customers/{customer_id}/orders');
        $this->parameter($getOrders, 'customer_id', 'path', 'integer');
        Http::fake(['*' => Http::response(['orders' => [['number' => 'I016426']]])]);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            $this->planResponse([
                'decision' => 'tools',
                'actions' => [[
                    'id' => 'get_customer_orders',
                    'tool' => 'get_orders',
                    'arguments' => [
                        'customer_id' => ['$from' => 'current_selection', 'path' => 'id'],
                    ],
                    'depends_on' => [],
                    'purpose' => 'Recupero gli ordini del cliente selezionato',
                ]],
            ]),
            $this->planResponse(['decision' => 'answer', 'actions' => []]),
        );
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $run = $this->makeRun();
        $run->forceFill(['input_json' => [
            'question' => 'Ho selezionato questa riga. Continua la richiesta precedente.',
            'selection' => [
                'tool' => 'search-customers',
                'row_key' => '147762',
                'record' => ['id' => 147762, 'display_name' => 'Ioanne Cro'],
            ],
        ]])->save();

        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('answer', $outcome->decision);
        $execution = $run->toolExecutions()->sole();
        $this->assertSame('completed', $execution->status);
        $this->assertSame(147762, $execution->arguments_json['customer_id']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/customers/147762/orders'));
    }

    public function test_it_reuses_a_successful_observation_instead_of_repeating_the_same_call(): void
    {
        $this->route('get_order', 'http://erp.example.test/orders/DEMO-3007');
        Http::fake(['*' => Http::response([
            'status' => 'completed',
            'data' => [
                'public_id' => 'DEMO-3007',
                'commercial_status' => 'completed',
                'total' => ['amount_minor' => 8990, 'currency' => 'EUR'],
            ],
        ])]);

        $requests = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')
            ->twice()
            ->withArgs(function (string $system, array $history) use (&$requests): bool {
                $requests[] = json_decode(
                    (string) data_get($history, '0.content'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                return true;
            })
            ->andReturn(
                $this->planResponse([
                    'decision' => 'tools',
                    'actions' => [[
                        'id' => 'load_order',
                        'tool' => 'get_order',
                        'arguments' => [],
                        'depends_on' => [],
                        'purpose' => 'Recupero il dettaglio ordine',
                    ]],
                ]),
                $this->planResponse([
                    'decision' => 'tools',
                    'actions' => [[
                        'id' => 'load_order_again',
                        'tool' => 'get_order',
                        'arguments' => [],
                        'depends_on' => [],
                        'purpose' => 'Recupero nuovamente il dettaglio ordine',
                    ]],
                ]),
            );
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $run = $this->makeRun();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('answer', $outcome->decision);
        $this->assertSame('duplicate_call_avoided', $outcome->stopReason);
        $this->assertCount(1, $outcome->completedActions);
        $this->assertSame('DEMO-3007', data_get(
            $requests,
            '1.evidence_summary.tool_results.0.record_preview.public_id',
        ));
        $this->assertSame('duplicate_call_avoided', data_get(
            $outcome->evidence->jsonSerialize(),
            'warnings.0.code',
        ));
        $this->assertSame(1, $run->toolExecutions()->count());
        Http::assertSentCount(1);
    }

    public function test_it_preserves_collected_evidence_when_replanning_fails(): void
    {
        $this->route('get_order', 'http://erp.example.test/orders/DEMO-3007');
        Http::fake(['*' => Http::response([
            'status' => 'completed',
            'data' => ['public_id' => 'DEMO-3007', 'commercial_status' => 'completed'],
        ])]);

        $calls = 0;
        $firstPlan = $this->planResponse([
            'decision' => 'tools',
            'actions' => [[
                'id' => 'load_order',
                'tool' => 'get_order',
                'arguments' => [],
                'depends_on' => [],
                'purpose' => 'Recupero il dettaglio ordine',
            ]],
        ]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturnUsing(
            function () use (&$calls, $firstPlan): AiResponse {
                if ($calls++ === 0) {
                    return $firstPlan;
                }

                throw new \RuntimeException('Planner did not return structured JSON.');
            },
        );
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $run = $this->makeRun();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('partial', $outcome->decision);
        $this->assertSame('planner_recovery', $outcome->stopReason);
        $this->assertCount(1, $outcome->completedActions);
        $this->assertSame('planner_recovery', data_get(
            $outcome->evidence->jsonSerialize(),
            'warnings.0.code',
        ));
        $this->assertSame('DEMO-3007', data_get(
            $outcome->evidence->jsonSerialize(),
            'api_tools.0.result.data.public_id',
        ));
        $this->assertSame(1, $run->toolExecutions()->count());
        Http::assertSentCount(1);
    }

    public function test_shadow_mode_records_capability_proposal_without_executing_tools(): void
    {
        config()->set('agent.planner.mode', 'shadow');
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->times(3)->andReturn(
            $this->planResponse(['decision' => 'answer', 'actions' => []]),
            new AiResponse(
                content: '', provider: 'fake', model: 'fake-agent',
                toolCalls: [['name' => 'submit_capability_route', 'arguments' => [
                    'live_data_required' => false,
                    'entity' => 'knowledge',
                    'operation' => 'search',
                    'candidate_tools' => ['search_knowledge_base'],
                    'reason_codes' => ['policy_question'],
                ]]],
            ),
            $this->planResponse(['decision' => 'answer', 'actions' => []]),
        );
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $run = $this->makeRun();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('answer', $outcome->decision);
        $this->assertCount(0, $run->toolExecutions);
        $report = $run->plannerShadowReports()->sole();
        $this->assertSame('shadow', $report->mode);
        $this->assertSame('agreement', $report->status);
        $this->assertSame(['policy_question'], $report->route_json['reason_codes']);
        $this->assertFalse($report->fallback_used);
    }

    public function test_capability_mode_routes_a_live_product_question_before_answering(): void
    {
        config()->set('agent.planner.mode', 'capability');
        $route = $this->route('search_products', 'http://erp.example.test/products');
        $this->parameter($route, 'query', 'query');
        Http::fake(['*' => Http::response(['items' => [['id' => 9, 'name' => 'Botanical Candle']]])]);

        $routerResponse = new AiResponse(
            content: '', provider: 'fake', model: 'fake-agent',
            toolCalls: [['name' => 'submit_capability_route', 'arguments' => [
                'live_data_required' => true,
                'entity' => 'products',
                'operation' => 'search',
                'candidate_tools' => ['search_products'],
                'reason_codes' => ['live_product_lookup'],
            ]]],
        );
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->times(4)->andReturn(
            $routerResponse,
            $this->planResponse(['decision' => 'tools', 'actions' => [[
                'id' => 'products',
                'tool' => 'search_products',
                'arguments' => ['query' => 'Botanical Candle'],
                'depends_on' => [],
                'purpose' => 'Cerco il prodotto nel catalogo live',
            ]]]),
            $routerResponse,
            $this->planResponse(['decision' => 'answer', 'actions' => []]),
        );
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $run = $this->makeRun();
        $run->forceFill(['input_json' => ['question' => 'Esiste il prodotto Botanical Candle?']])->save();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('answer', $outcome->decision);
        $this->assertSame('search_products', $run->toolExecutions()->sole()->tool_name);
        $this->assertCount(2, $run->plannerShadowReports);
        $this->assertTrue($run->plannerShadowReports->every(fn ($report): bool => ! $report->fallback_used));
        Http::assertSentCount(1);
    }

    /** @param array<string,mixed> $payload */
    private function planResponse(array $payload): AiResponse
    {
        return new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake-agent',
            toolCalls: [['name' => 'submit_agent_plan', 'arguments' => $payload]],
        );
    }

    private function route(string $slug, string $url): ApiRoute
    {
        $connector = ApiConnector::firstOrCreate([
            'tenant_id' => 'acme',
            'project_key' => 'crm',
            'name' => 'ERP',
        ], ['is_active' => true]);

        return ApiRoute::create([
            'tenant_id' => 'acme',
            'api_connector_id' => $connector->id,
            'project_key' => 'crm',
            'name' => $slug,
            'slug' => $slug,
            'http_method' => 'GET',
            'url' => $url,
            'mode' => 'tool',
            'status' => 'active',
            'endpoint_type' => 'detail',
            'tool_definition' => [
                'name' => $slug,
                'description' => $slug,
                'input_schema' => ['type' => 'object', 'properties' => []],
            ],
        ])->load('parameters');
    }

    private function parameter(ApiRoute $route, string $name, string $location, string $type = 'string'): void
    {
        ApiRouteParameter::create([
            'tenant_id' => 'acme',
            'api_route_id' => $route->id,
            'name' => $name,
            'location' => $location,
            'source' => 'llm',
            'type' => $type,
            'required' => true,
        ]);
    }

    private function makeRun(): AgentRun
    {
        return AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'acme',
            'project_key' => 'crm',
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => '1',
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING,
            'input_json' => ['question' => 'Dammi tutti gli ordini di Tizio'],
            'budget_json' => [],
            'counters_json' => [],
            'started_at' => now(),
        ]);
    }

    private function context(AgentRun $run): AgentExecutionContext
    {
        return AgentExecutionContext::fromArray([
            'run_id' => $run->run_id,
            'tenant_id' => $run->tenant_id,
            'project_key' => $run->project_key,
            'channel' => $run->channel,
            'actor_type' => $run->actor_type,
            'actor_id' => $run->actor_id,
            'locale' => $run->locale,
            'timezone' => $run->timezone,
        ]);
    }
}
