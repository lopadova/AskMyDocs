<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Contracts\AgentRunHandler;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RejectedMixedPlanRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION = 'Incrocia U726180, U726256 e RU85199: confronta articoli e taglie con Gescat. Il rimborso è una proposta, un’autorizzazione condizionata o un pagamento provato? Indica la condizione ancora da verificare.';
    private const ORDERS = ['U726180' => 40, 'U726256' => 41];
    private const RETURN_ITEM = 'RU85199 riguarda AULW-VA01 in taglia 40.';
    private const QUOTE = 'Ad ogni modo, se il paio è lo stesso ed è conforme, direi di procedere al rimborso';
    private const CONDITION = 'Ma prima di procedere con il rimborso chiediamo a U726256 conferma di cosa ha ricevuto (sperando risponda).';
    private const ENDPOINT = 'https://gescat.example.test/orders';
    private const TOOL = 'gescat_cerca_ordini';

    public static function plannerOutcomes(): array
    {
        return [
            'ordinary planner also rejected' => [true],
            'ordinary planner omits the correspondence' => [false],
            'malformed draft then connection failure' => [false, true],
            'research worker hands off a failed draft with sources' => [false, true, true],
            'correspondence revoked before recovery' => [false, true, false, true],
        ];
    }

    #[DataProvider('plannerOutcomes')]
    public function test_rejected_plan_recovers_the_order_comparison_and_documentary_conclusion(
        bool $ordinaryRejected,
        bool $synthesisFailed = false,
        bool $concurrent = false,
        bool $revoked = false,
    ): void
    {
        config(['agent.waves.enabled' => true, 'agent.waves.combined_initial_planning' => true,
            'agent.planner.mode' => 'classic', 'reasoning.enabled' => true,
            'reasoning.selective_validation' => true, 'kb.investigation.enabled' => false,
            'connector-api.ssrf.resolve_dns' => false, 'ai.providers.openrouter.key' => 'fixture']);
        Queue::fake();
        Http::preventStrayRequests();
        app(TenantContext::class)->set('acme');
        $user = User::create(['name' => 'QA', 'email' => 'recovery@example.test', 'password' => bcrypt('fixture')]);
        $this->actingAs($user);
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'returns', 'user_id' => $user->id, 'role' => 'member']);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'returns', 'user_id' => $user->id]);
        $message = $conversation->messages()->create(['role' => 'user', 'content' => self::QUESTION]);
        $connector = $this->registerGescat();
        $run = AgentRun::create(['run_id' => Str::uuid()->toString(), 'tenant_id' => 'acme', 'project_key' => 'returns',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id,
            'conversation_id' => $conversation->id, 'locale' => 'it', 'timezone' => 'Europe/Rome', 'status' => 'queued',
            'input_json' => ['question' => $message->content, 'user_message_id' => $message->id, 'session_evidence' => true,
                'concurrent_session' => $concurrent,
                'live_sources' => ['api' => ['api:'.$connector->id], 'mcp' => []]]]);

        $excerpt = self::RETURN_ITEM.' '.self::QUOTE.".\n".self::CONDITION;
        $hash = hash('sha256', $excerpt);
        $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'returns', 'source_type' => 'markdown',
            'title' => 'Corrispondenza sul reso RU85199', 'source_path' => 'ru85199.md', 'document_hash' => $hash,
            'version_hash' => $hash, 'status' => 'active']);
        $chunk = $document->chunks()->create(['tenant_id' => 'acme', 'project_key' => 'returns',
            'chunk_order' => 0, 'chunk_text' => $excerpt, 'chunk_hash' => $hash]);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect([
            ['chunk_id' => $chunk->id, 'chunk_text' => $excerpt, 'document' => $document->toArray()],
        ]), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $drafts = 0;
        $this->fakeProviders($ordinaryRejected, $synthesisFailed, $drafts, $revoked ? $document : null);

        for ($delivery = 0; $delivery < 8 && ! $run->fresh()->isTerminal(); $delivery++) {
            app(AgentRunHandler::class)->handle($run->fresh());
        }
        app(AgentRunHandler::class)->handle($run->fresh());
        $run->refresh();
        $this->assertSame($synthesisFailed ? AgentRun::STATUS_PARTIAL : AgentRun::STATUS_COMPLETED, $run->status);
        $this->assertArrayNotHasKey('initial_plan', $run->result_json);
        if ($ordinaryRejected) {
            $this->assertTrue($run->events()->where('payload_json->data->operation', 'initial_document_recovery')->exists());
        }
        $this->assertEqualsCanonicalizing(['search_knowledge_base', self::TOOL, self::TOOL],
            $run->toolExecutions()->pluck('tool_name')->all());
        $answer = $run->result_json['response'];
        $this->assertSame($synthesisFailed ? 'partial' : 'complete', $answer['completeness']);
        $this->assertSame($revoked ? [] : [$document->id], array_column($answer['citations'], 'document_id'));
        $this->assertCount(2, $answer['tool_sources']);
        $body = $conversation->messages()->where('role', 'assistant')->sole()->content;
        if ($synthesisFailed) {
            $this->assertSame(2, $drafts);
            $this->assertSame('synthesis_unavailable', $run->result_json['stop_reason']);
            $this->assertSame('verification_unavailable', data_get($answer, 'grounding.coverage_validation.next_action'));
            $this->assertSame([], $answer['grounding']['claims']);
            $this->assertStringContainsString('| U726180 | AULW-VA01-2025.1_2025 | 40 |', html_entity_decode($body));
            $this->assertStringContainsString('| U726256 | AULW-VA01-2025.1_2025 | 41 |', html_entity_decode($body));
            if ($revoked) {
                $this->assertStringNotContainsString(self::QUOTE, html_entity_decode($body));
            } else {
                $this->assertStringContainsString(self::QUOTE, html_entity_decode($body));
            }
        } else {
            $this->assertStringContainsString('U726180: AULW-VA01, taglia 40', $body);
            $this->assertStringContainsString('U726256: AULW-VA01, taglia 41', $body);
            $this->assertStringContainsString('autorizzazione condizionata', $body);
        }
        if ($revoked) {
            $this->assertStringNotContainsString(self::RETURN_ITEM, $body);
            $this->assertStringNotContainsString('conferma di cosa ha ricevuto', $body);
        } else {
            $this->assertStringContainsString(self::RETURN_ITEM, $body);
            $this->assertStringContainsString('conferma di cosa ha ricevuto', $body);
        }
        foreach (array_keys(self::ORDERS) as $id) {
            Http::assertSent(fn ($request) => $request->url() === self::ENDPOINT.'/'.$id);
        }
    }

    private function registerGescat(): ApiConnector
    {
        $connector = ApiConnector::create(['tenant_id' => 'acme', 'project_key' => 'returns', 'name' => 'Gescat', 'is_active' => true]);
        $route = ApiRoute::create(['tenant_id' => 'acme', 'api_connector_id' => $connector->id, 'project_key' => 'returns',
            'name' => self::TOOL, 'slug' => self::TOOL, 'http_method' => 'GET', 'url' => self::ENDPOINT.'/{ord_codiceweb}',
            'mode' => 'tool', 'status' => 'active', 'endpoint_type' => 'detail', 'items_path' => 'order', 'identity_fields' => ['ord_codiceweb'],
            'tool_definition' => ['name' => self::TOOL, 'description' => 'Read an order with its items and sizes by web order code',
                'input_schema' => ['type' => 'object', 'required' => ['ord_codiceweb'],
                    'properties' => ['ord_codiceweb' => ['type' => 'string', 'pattern' => '^U[0-9]+$', 'x-require-observed-identifier' => true]]]]]);
        ApiRouteParameter::create(['tenant_id' => 'acme', 'api_route_id' => $route->id, 'name' => 'ord_codiceweb',
            'location' => 'path', 'source' => 'llm', 'type' => 'string', 'required' => true]);
        $route->forceFill(['output_schema' => [
            'type' => 'object', 'properties' => ['order' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'articoli' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'articolo' => ['type' => 'array', 'x-field' => 'items', 'items' => ['type' => 'object', 'properties' => [
                        'art_pcode' => ['type' => 'string', 'x-field' => 'reference'],
                        'variante' => ['type' => 'integer', 'x-field' => 'variant'],
                    ]]],
                ]]],
            ]]]],
        ]])->save();

        return $connector;
    }

    private function fakeProviders(bool $ordinaryRejected, bool $synthesisFailed, int &$drafts, ?KnowledgeDocument $revoked): void
    {
        Http::fake(function ($request) {
            if (str_starts_with($request->url(), self::ENDPOINT.'/')) {
                $id = basename($request->url());
                return Http::response(['order' => [['ord_codiceweb' => $id,
                    'articoli' => [['articolo' => [['art_pcode' => 'AULW-VA01-2025.1_2025', 'variante' => self::ORDERS[$id]]]]]]]]);
            }
            $answers = [];
            foreach ($request['questions'] as $key => $criterion) {
                if ($criterion['type'] === 'noul') {
                    $answers[$key] = ['type' => 'noul', 'noul' => .99];
                    continue;
                }
                $probabilities = array_fill_keys(array_keys($criterion['criteria']), 0);
                $first = array_key_first($probabilities);
                $probabilities[$first] = 1;
                $answers[$key] = ['type' => 'choice', 'choice' => $first, 'confidence' => 1, 'probabilities' => $probabilities];
            }
            return Http::response(['model' => 'fixture', 'answers' => $answers]);
        });

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->andReturnUsing(function ($provider, $system, $messages, $options) {
            $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(self::QUESTION, $input['question']);
            $repair = isset($input['validation_error']);
            if ($repair) $this->assertSame('invalid_mixed_source_tasks', $input['validation_error']);
            $current = ['kind' => 'comparison', 'quantity' => null, 'freshness' => 'current'];
            $orders = ['topic' => 'order', 'aspect' => 'articoli e taglie', 'identifiers' => [array_key_first(self::ORDERS)], 'fields' => ['items', 'sizes']];
            $refund = ['topic' => 'return', 'aspect' => 'rimborso e condizione', 'identifiers' => ['RU85199'], 'fields' => ['refund_status', 'condition']];
            $query = 'RU85199 rimborso condizione';
            $data = ['language' => 'it', 'intent' => self::QUESTION, 'action' => 'research',
                'kb_queries' => [$query], 'mentions' => array_map(fn ($id) => ['text' => $id, 'type' => 'identifier'], [...array_keys(self::ORDERS), 'RU85199']),
                'references_previous_turn' => false, 'focus' => $orders, 'context_mode' => 'new', 'requires_research' => true,
                'requires_composition' => true, 'transition' => 'new', 'resolved_references' => [], 'needs_clarification' => false,
                'clarification' => '', 'deliverable' => $current, 'subquestions' => [
                    ...array_map(fn ($id) => [...$orders, 'identifiers' => [$id], 'question' => 'Quali articoli e taglie risultano in Gescat per '.$id.'?',
                        'context_mode' => 'new', 'requires_research' => true, 'kb_queries' => [], 'deliverable' => $current], array_keys(self::ORDERS)),
                    $refund + ['question' => 'Quale condizione e autorizzazione sul reso RU85199 emergono dalle email?', 'context_mode' => 'new',
                        'requires_research' => true, 'kb_queries' => [$query],
                        'deliverable' => [...$current, 'freshness' => $repair ? 'source_context' : 'current']],
                ]];
            if (isset($options['response_format']['json_schema']['schema']['properties']['memory_decisions'])) $data['memory_decisions'] = [];
            return new AiResponse(json_encode($data, JSON_THROW_ON_ERROR), 'fake', 'fixture');
        });
        $attempts = 0;
        $ai->shouldReceive('chatWithHistory')->andReturnUsing(function ($system, $messages, $options) use (&$attempts, &$drafts, $ordinaryRejected, $synthesisFailed, $revoked) {
            $state = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            $tool = $options['tool_choice']['function']['name'];
            if ($tool === 'submit_agent_plan') {
                $attempts++;
                if ($attempts === 1) $this->assertNull($state['validation_error']);
                if ($ordinaryRejected && $attempts === 2) $this->assertStringContainsString('requested_source_coverage_required', $state['validation_error']);
                $lookedUp = in_array(self::TOOL, array_column($state['completed_actions'], 'tool'), true);
                $actions = [];
                if (! $lookedUp && (! $ordinaryRejected || $state['completed_actions'] !== [])) {
                    foreach (array_keys(self::ORDERS) as $id) {
                        $actions[] = ['id' => 'lookup_'.strtolower($id), 'tool' => self::TOOL, 'arguments' => ['ord_codiceweb' => $id],
                            'depends_on' => [], 'purpose' => 'Leggi articoli e taglie'];
                    }
                }
                $payload = ['decision' => $actions === [] ? 'answer' : 'tools', 'actions' => $actions];
            } else {
                $drafts++;
                if ($synthesisFailed) {
                    if ($drafts === 1) {
                        return new AiResponse('Not a structured draft.', 'fake', 'fixture');
                    }
                    $revoked?->delete();
                    throw new ConnectionException('Simulated synthesis timeout.');
                }
                $claims = [];
                foreach ($state['evidence']['api_tools'] as $observation) {
                    $record = $observation['result']['order'][0];
                    $id = $record['ord_codiceweb'];
                    $claims[] = ['tool_execution_id' => $observation['execution_id'], 'evidence_hash' => $observation['evidence_hash'],
                        'text' => $id.': AULW-VA01, taglia '.$record['articoli'][0]['articolo'][0]['variante'], 'quote' => 'AULW-VA01-2025.1_2025',
                        'subquestion_id' => array_search($id, array_keys(self::ORDERS), true)];
                }
                $doc = $state['evidence']['documents'][0];
                $passage = $doc['evidence'][0];
                foreach ([self::RETURN_ITEM => self::RETURN_ITEM,
                    self::QUOTE => 'RU85199: autorizzazione condizionata alla conformità del paio; il pagamento non è provato da questa email.',
                    self::CONDITION => 'RU85199: resta da ottenere da U726256 conferma di cosa ha ricevuto.'] as $quote => $text) {
                    $claims[] = ['text' => $text, 'quote' => $quote, 'document_id' => $doc['document_id'],
                        'evidence_hash' => $passage['evidence_hash'], 'subquestion_id' => 2];
                }
                $payload = ['completeness' => 'complete', 'claims' => $claims, 'limitations' => [], 'requires_selection' => false, 'render_table' => false];
            }
            return new AiResponse('', 'fake', 'fixture', toolCalls: [['name' => $tool, 'arguments' => $payload]]);
        });
        $this->app->instance(AiManager::class, $ai);
    }
}
