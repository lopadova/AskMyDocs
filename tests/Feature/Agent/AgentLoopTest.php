<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoop;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Investigation\KbInvestigationResult;
use App\Services\Kb\Investigation\KbInvestigationService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
        config()->set('kb.investigation.enabled', false);
    }

    public static function invalidIdentifierCases(): array
    {
        return ['wrong namespace' => ['RETURN-42', false], 'invented but valid format' => ['ORDER-43', true]];
    }

    #[DataProvider('invalidIdentifierCases')]
    public function test_repeated_invalid_entity_branch_never_dispatches_and_valid_read_still_completes(string $invalid, bool $observed): void
    {
        $route = $this->route('lookup_orders', 'http://erp.example.test/orders');
        $this->parameter($route, 'reference', 'query');
        $definition = $route->tool_definition;
        $definition['input_schema']['properties']['reference'] = ['type' => 'string', 'pattern' => '^ORDER-[0-9]+$'];
        if ($observed) {
            $definition['input_schema']['properties']['reference']['x-require-observed-identifier'] = true;
        }
        $route->forceFill(['tool_definition' => $definition, 'input_schema' => $definition['input_schema']])->save();
        Http::fake(['*' => Http::response(['id' => 42, 'reference' => 'ORDER-42', 'status' => 'SHIPPED'])]);
        $ai = Mockery::mock(AiManager::class);
        $plan = ['decision' => 'tools', 'actions' => array_map(fn ($id, $reference) => [
            'id' => $id, 'tool' => 'lookup_orders', 'arguments' => ['reference' => $reference], 'depends_on' => [], 'purpose' => 'Read record',
        ], ['valid', 'invalid'], ['ORDER-42', $invalid])];
        $ai->shouldReceive('chatWithHistory')->times(3)->andReturn($this->planResponse($plan), $this->planResponse($plan),
            $this->planResponse(['decision' => 'answer', 'actions' => []]));
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = $this->makeRun();
        $user = \App\Models\User::create(['name' => 'QA user',
            'email' => 'qa@example.invalid', 'password' => 'unused-test-password']);
        $run->forceFill(['user_id' => $user->id, 'actor_id' => (string) $user->id,
            'input_json' => [...$run->input_json, 'question' => 'Read ORDER-42 and RETURN-43.']])->save();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('answer', $outcome->decision);
        $this->assertSame(['skipped', 'completed'], array_column($outcome->completedActions, 'status'));
        $this->assertSame('input_pattern_branch_rejected', $outcome->completedActions[0]['error_code']);
        $this->assertSame(['reference' => 'ORDER-42'], $outcome->evidence->apiTools()[0]['arguments']);
        $this->assertSame(1, $run->fresh()->counters_json['physical_calls']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'ORDER-42') && ! str_contains($request->url(), $invalid));
    }

    public function test_it_chains_customer_lookup_into_orders_and_replans_with_combined_evidence(): void
    {
        $findCustomer = $this->route('find_customer', 'http://erp.example.test/customers');
        $this->parameter($findCustomer, 'name', 'query');
        $findCustomer->forceFill(['output_schema' => [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'integer']],
                        'required' => ['id'],
                    ],
                ],
            ],
            'required' => ['items'],
        ]])->save();
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
            Mockery::type(RetrievalFilters::class),
            true,
            Mockery::type(\Closure::class),
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

    public function test_classic_mode_keeps_legacy_dependency_failure_without_external_call(): void
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
        $execution = $run->toolExecutions()->sole();
        $this->assertSame('skipped', $execution->status);
        $this->assertSame('dependency_resolution_failed', $execution->error_code);
        $this->assertSame(0, $execution->physical_request_count);
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

    public function test_it_normalizes_a_reused_action_id_and_avoids_a_duplicate_physical_read(): void
    {
        $this->route('get_order', 'http://erp.example.test/orders/DEMO-3007');
        Http::fake(['*' => Http::response([
            'status' => 'completed',
            'data' => ['public_id' => 'DEMO-3007', 'commercial_status' => 'completed'],
        ])]);

        $requests = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')
            ->times(2)
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
                        'id' => 'load_order',
                        'tool' => 'get_order',
                        'arguments' => [],
                        'depends_on' => [],
                        'purpose' => 'Riutilizzo per errore lo stesso identificatore',
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
        $this->assertNull(data_get($requests, '1.validation_error'));
        $this->assertSame('step_1', data_get($run->fresh()->plan_json, 'actions.0.id'));
        $this->assertSame(1, $run->toolExecutions()->count());
        Http::assertSentCount(1);
    }

    public function test_exhaustive_search_cannot_answer_before_using_an_available_live_source(): void
    {
        $search = $this->route('search_people', 'http://erp.example.test/people');
        $this->parameter($search, 'query', 'query');
        Http::fake(['*' => Http::response(['items' => [['id' => 9, 'name' => 'Giulia Riva']]])]);

        $requests = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')
            ->times(3)
            ->withArgs(function (string $system, array $history) use (&$requests): bool {
                $requests[] = json_decode(
                    (string) data_get($history, '0.content'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                return true;
            })
            ->andReturn(
                $this->planResponse(['decision' => 'answer', 'actions' => []]),
                $this->planResponse([
                    'decision' => 'tools',
                    'actions' => [[
                        'id' => 'search_giulia_live',
                        'tool' => 'search_people',
                        'arguments' => ['query' => 'Giulia Riva'],
                        'depends_on' => [],
                        'purpose' => 'Cerco Giulia Riva nella fonte live',
                    ]],
                ]),
                $this->planResponse(['decision' => 'answer', 'actions' => []]),
            );
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')
            ->once()
            ->with(
                'Cerca tutto quello che riesci a trovare su Giulia Riva e fammi un riassunto',
                'crm',
                Mockery::type(RetrievalFilters::class),
                true,
                Mockery::type(\Closure::class),
            )
            ->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $run = $this->makeRun();
        $run->forceFill(['input_json' => [
            'question' => 'Cerca tutto quello che riesci a trovare su Giulia Riva e fammi un riassunto',
        ]])->save();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('answer', $outcome->decision);
        $this->assertStringContainsString(
            'live_source_coverage_required',
            (string) data_get($requests, '1.validation_error'),
        );
        $this->assertSame('search_people', $run->toolExecutions()->sole()->tool_name);
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

    public function test_initial_agent_evidence_uses_the_selected_recursive_kb_sources_only(): void
    {
        config()->set('kb.investigation.enabled', true);
        $ai = Mockery::mock(AiManager::class);
        $this->expectResearchInterpretation($ai);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->planResponse(['decision' => 'answer', 'actions' => []]));
        $this->app->instance(AiManager::class, $ai);

        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldNotReceive('retrieve');
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $selected = [
            'chunk_id' => 101,
            'chunk_hash' => 'selected-hash',
            'chunk_text' => 'Ordine Tizio #42 consegnato.',
            'project_key' => 'crm',
            'vector_score' => 0.9,
            'document' => [
                'id' => 11,
                'title' => 'Esito ordine Tizio',
                'source_path' => 'mail/tizio-42',
                'source_type' => 'text',
            ],
        ];
        $investigation = Mockery::mock(KbInvestigationService::class);
        $investigation->shouldReceive('investigate')->once()->andReturn(new KbInvestigationResult(
            'ready',
            'sufficient_evidence',
            new SearchResult(collect([$selected]), collect(), collect()),
            [$selected],
            ['ordine cliente Tizio stato consegna'],
        ));
        $this->app->instance(KbInvestigationService::class, $investigation);

        $run = $this->makeRun();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('answer', $outcome->decision);
        $documents = $outcome->evidence->documents();
        $this->assertCount(1, $documents);
        $this->assertSame(11, $documents[0]['document_id']);
        $this->assertSame('Ordine Tizio #42 consegnato.', $documents[0]['evidence'][0]['content']);
    }

    public static function initialPlanningEvidence(): array
    {
        return ['original source already read' => [true, false], 'no evidence' => [false, false],
            'required live coverage still blocks' => [true, true]];
    }

    #[DataProvider('initialPlanningEvidence')]
    public function test_invalid_initial_plans_only_recover_when_a_source_was_already_read(bool $withEvidence, bool $coverageFailure): void
    {
        config(['kb.investigation.enabled' => true]);
        Http::preventStrayRequests();
        $ai = Mockery::mock(AiManager::class);
        $this->expectResearchInterpretation($ai);
        $payload = $coverageFailure ? ['decision' => 'answer', 'actions' => []] : [
            'decision' => 'tools', 'actions' => [[
                'id' => 'fetch_café', 'tool' => 'search_knowledge_base',
                'arguments' => ['query' => 'café'], 'depends_on' => [], 'purpose' => 'Read the source',
            ]],
        ];
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn($this->planResponse($payload));
        $this->app->instance(AiManager::class, $ai);
        $source = ['chunk_id' => 101, 'chunk_hash' => 'source-hash',
            'chunk_text' => 'The recorded reply promises a replacement.', 'project_key' => 'crm', 'vector_score' => .9,
            'document' => ['id' => 11, 'title' => 'Recorded reply', 'source_path' => 'mail/reply', 'source_type' => 'text']];
        $sources = $withEvidence ? [$source] : [];
        $investigation = Mockery::mock(KbInvestigationService::class);
        $investigation->shouldReceive('investigate')->once()->andReturn(new KbInvestigationResult(
            'ready', 'no_new_evidence', new SearchResult(collect($sources), collect(), collect()), $sources,
        ));
        $this->app->instance(KbInvestigationService::class, $investigation);
        $run = $this->makeRun();
        if ($coverageFailure) {
            $this->route('search_people', 'http://erp.example.test/people');
            $run->forceFill(['input_json' => ['question' => 'Cerca tutto quello che riesci a trovare su Tizio']])->save();
            $this->expectException(\App\Agent\Planning\AgentPlanValidationException::class);
            $this->expectExceptionMessage('exhaustive search');
        }
        if (! $withEvidence) {
            $this->expectException(\UnexpectedValueException::class);
            $this->expectExceptionMessage('invalid or duplicated');
        }
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('partial', $outcome->decision);
        $this->assertSame('planner_recovery', $outcome->stopReason);
        $this->assertSame('The recorded reply promises a replacement.', $outcome->evidence->documents()[0]['evidence'][0]['content']);
        $this->assertSame([], $outcome->completedActions);
        $this->assertNull($run->fresh()->plan_json);
        $this->assertSame(0, $run->toolExecutions()->count());
        Http::assertNothingSent();
    }

    public function test_empty_recursive_kb_does_not_prevent_authorized_connector_research(): void
    {
        config(['kb.investigation.enabled' => true]);
        $this->route('search_shipments', 'http://erp.example.test/shipments');
        Http::fake(['erp.example.test/*' => Http::response(['id' => 'SHIP-53', 'status' => 'shipped'])]);
        $ai = Mockery::mock(AiManager::class);
        $this->expectResearchInterpretation($ai);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            $this->planResponse(['decision' => 'tools', 'actions' => [[
                'id' => 'shipment', 'tool' => 'search_shipments', 'arguments' => [],
                'depends_on' => [], 'purpose' => 'Find shipment in the authorized connector',
            ]]]), $this->planResponse(['decision' => 'answer', 'actions' => []]));
        $this->app->instance(AiManager::class, $ai);
        $investigation = Mockery::mock(KbInvestigationService::class);
        $investigation->shouldReceive('investigate')->once()->andReturn(new KbInvestigationResult(
            'no_evidence', 'no_new_evidence', new SearchResult(collect(), collect(), collect()),
            attempts: [['query' => 'shipment emails', 'outcome' => 'no_readable_sources']]));
        $this->app->instance(KbInvestigationService::class, $investigation);

        $run = $this->makeRun();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));

        $this->assertSame('answer', $outcome->decision);
        $this->assertSame('search_shipments', $run->toolExecutions()->sole()->tool_name);
        $this->assertCount(1, $outcome->evidence->apiTools());
        $this->assertSame('no_readable_sources', data_get($run->fresh()->result_json, 'knowledge_investigation.attempts.0.outcome'));
        Http::assertSentCount(1);
    }

    public function test_missing_profile_or_ambiguous_focus_remain_hard_stops_even_after_resume(): void
    {
        config(['kb.investigation.enabled' => true]);
        foreach (['profile_required' => 'retrieval_profile_required', 'clarify' => 'focus_ambiguous'] as $status => $reason) {
            $ai = Mockery::mock(AiManager::class);
            $ai->shouldNotReceive('chatWithHistory');
            $this->app->instance(AiManager::class, $ai);
            $investigation = Mockery::mock(KbInvestigationService::class);
            $investigation->shouldReceive('investigate')->once()->andReturn(new KbInvestigationResult(
                $status, $reason, new SearchResult(collect(), collect(), collect())));
            $this->app->instance(KbInvestigationService::class, $investigation);
            $run = $this->makeRun();
            $loop = app(AgentLoop::class);
            $this->assertSame($reason, $loop->run($run, $this->context($run))->stopReason);
            $this->assertSame($reason, $loop->run($run->fresh(), $this->context($run))->stopReason);
            $this->assertSame(0, $run->toolExecutions()->count());
        }
    }

    private function expectResearchInterpretation(AiManager $ai): void
    {
        $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse(json_encode([
            'language' => 'it', 'intent' => 'Stato dell’ordine del cliente Tizio', 'action' => 'research',
            'kb_queries' => ['ordine cliente Tizio stato consegna'], 'mentions' => [],
            'references_previous_turn' => false, 'context_mode' => 'new', 'requires_research' => true,
        ]), 'fake', 'test'));
    }

    public function test_ambiguous_or_unavailable_interpretation_stops_before_any_search_or_tools_even_on_resume(): void
    {
        config(['kb.investigation.enabled' => true]);
        $this->route('get_orders', 'http://erp.example.test/orders');
        Http::preventStrayRequests();
        foreach (['focus_ambiguous', 'question_interpretation_unavailable'] as $reason) {
            $ai = Mockery::mock(AiManager::class);
            $interpretation = $ai->shouldReceive('chatWithProvider')->once();
            if ($reason === 'focus_ambiguous') {
                $interpretation->andReturn(new AiResponse(json_encode([
                    'language' => 'it', 'intent' => 'Quale ordine vuoi controllare?', 'action' => 'clarify',
                    'kb_queries' => [], 'mentions' => [], 'references_previous_turn' => false,
                    'context_mode' => 'new', 'requires_research' => false,
                ]), 'fake', 'test'));
            } else {
                $interpretation->andThrow(new \RuntimeException('Interpretation provider unavailable'));
            }
            $ai->shouldNotReceive('chatWithHistory');
            $ai->shouldNotReceive('chat');
            $this->app->instance(AiManager::class, $ai);
            $this->app->forgetInstance(\App\Services\Chat\ChatTurnInterpreter::class);
            $this->app->forgetInstance(\App\Services\Chat\ChatQuestionPreprocessor::class);
            $retrieval = Mockery::mock(ChatRetrievalService::class);
            $retrieval->shouldNotReceive('retrieve');
            $this->app->instance(ChatRetrievalService::class, $retrieval);
            $run = $this->makeRun();
            $run->forceFill(['input_json' => ['question' => 'Controllalo']])->save();
            $loop = app(AgentLoop::class);
            $outcome = $loop->run($run, $this->context($run));
            $this->assertSame($reason, $outcome->stopReason);
            $this->assertSame($reason, $loop->run($run->fresh(), $this->context($run))->stopReason);
            $this->assertSame(0, $run->events()->where('type', 'retrieval.started')->count());
            $this->assertSame(0, $run->events()->where('type', 'retrieval.completed')->count());
            $this->assertSame(0, $run->toolExecutions()->count());
            $this->assertNull($run->refresh()->plan_json);
            $answer = app(\App\Agent\AgentAnswerSynthesizer::class)->synthesize('Controllalo', $this->context($run), $outcome,
                understanding: $run->result_json['question_understanding']);
            $this->assertSame('partial', $answer->completeness);
            $this->assertSame('clarify', $answer->grounding['status']);
        }
        Http::assertNothingSent();
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

    public function test_greeting_bypasses_document_retrieval_planning_and_factual_synthesis(): void
    {
        config(['kb.investigation.enabled' => true]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse(json_encode([
            'language' => 'it', 'intent' => 'Salutare', 'action' => 'greeting', 'kb_queries' => [],
            'mentions' => [], 'references_previous_turn' => false,
        ]), 'fake', 'test'));
        $ai->shouldNotReceive('chatWithHistory');
        $ai->shouldNotReceive('chat');
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $retrieval->shouldNotReceive('retrieve');
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        Http::preventStrayRequests();
        $run = $this->makeRun();
        $run->forceFill(['input_json' => ['question' => 'Ciao']])->save();
        $context = $this->context($run);
        $outcome = app(AgentLoop::class)->run($run, $context);
        $this->assertSame('assistant_conversation', $outcome->stopReason);
        $this->assertSame([], $outcome->evidence->documents());
        $this->assertNull($run->refresh()->plan_json);
        $this->assertCount(0, $run->toolExecutions);
        $answer = app(\App\Agent\AgentAnswerSynthesizer::class)->synthesize('Ciao', $context, $outcome,
            understanding: $run->result_json['question_understanding']);
        $this->assertSame('Ciao! Come posso aiutarti?', $answer->answer);
        $this->assertSame([], $answer->citations);
        $this->assertSame('complete', $answer->completeness);
        Http::assertNothingSent();
    }

    public function test_capability_reply_uses_selected_project_tools_without_claiming_connectivity(): void
    {
        $route = $this->route('erp_get_orders', 'http://erp.example.test/orders');
        $other = $this->route('get_customers', 'http://erp.example.test/customers');
        $other->forceFill(['project_key' => 'other-project'])->save();
        Http::preventStrayRequests();
        $run = $this->makeRun();
        $understanding = new \App\Services\Chat\QuestionUnderstanding('it', 'Accesso agli ordini', [], [], false,
            focus: ['topic' => 'orders', 'identifiers' => [], 'aspect' => 'capabilities', 'fields' => []],
            action: \App\Services\Chat\QuestionAction::Capabilities);
        $responder = app(\App\Services\Chat\AssistantConversationResponder::class);
        $answer = $responder->answer($understanding, $this->context($run), selection: ['api' => ['api:'.$route->api_connector_id], 'mcp' => []]);
        $this->assertStringContainsString('erp_get_orders', $answer->answer);
        $this->assertStringNotContainsString('get_customers', $answer->answer);
        $this->assertFalse($answer->grounding['connectivity_verified']);
        $this->assertSame([], $answer->citations);
        $excluded = $responder->answer($understanding, $this->context($run), selection: ['api' => [], 'mcp' => []]);
        $this->assertStringNotContainsString('erp_get_orders', $excluded->answer);
        $this->assertNotContains('erp_get_orders', $excluded->grounding['available_tools']);
        Http::assertNothingSent();
    }

    public function test_interpreter_receives_selected_capabilities_before_any_search(): void
    {
        config(['kb.investigation.enabled' => true]);
        $selected = $this->route('get_orders', 'http://erp.example.test/orders');
        $unselectedConnector = ApiConnector::create(['tenant_id' => 'acme', 'project_key' => 'crm', 'name' => 'Other', 'is_active' => true]);
        $unselected = $this->route('get_customers', 'http://erp.example.test/customers');
        $unselected->forceFill(['api_connector_id' => $unselectedConnector->id])->save();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->withArgs(function ($provider, $prompt, $messages) {
            $input = json_decode($messages[0]['content'], true);
            $names = array_column($input['available_capabilities'], 'tool');
            $this->assertContains('get_orders', $names);
            $this->assertNotContains('get_customers', $names);
            return true;
        })->andReturn(new AiResponse(json_encode(['language' => 'it', 'intent' => 'Salutare', 'action' => 'greeting',
            'kb_queries' => [], 'mentions' => [], 'references_previous_turn' => false, 'context_mode' => 'new', 'requires_research' => false]), 'fake', 'test'));
        $ai->shouldNotReceive('chatWithHistory');
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $retrieval->shouldNotReceive('retrieve');
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = $this->makeRun();
        $run->forceFill(['input_json' => ['question' => 'Ciao', 'live_sources' => ['api' => ['api:'.$selected->api_connector_id], 'mcp' => []]]])->save();
        $outcome = app(AgentLoop::class)->run($run, $this->context($run));
        $this->assertSame('assistant_conversation', $outcome->stopReason);
        $this->assertFalse($run->events()->where('type', 'retrieval.started')->exists());
        $this->assertSame('new', $run->fresh()->result_json['question_understanding']['context_mode']);
        $this->assertNotNull($run->result_json['capability_manifest']['hash']);
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
