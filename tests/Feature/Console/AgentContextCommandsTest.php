<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Agent\AgentConsoleContext;
use App\Agent\AgentExecutionContext;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Tools\AgentToolRegistry;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Tests\TestCase;

final class AgentContextCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION = 'Mostra in tabella i resi CASE-A di Gescat e spiega la regola di rimborso.';
    private const POLICY = 'Per il reso CASE-A il rimborso richiede il controllo di conformità del paio.';
    private const EXPLANATION = 'Il rimborso del reso CASE-A richiede il controllo di conformità del paio.';
    private const ENDPOINT = 'https://gescat.example.test/returns';
    private const RECORDS = ['records' => [['id' => 'CASE-A', 'status' => 'awaiting_inspection']]];

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.waves.enabled' => true, 'agent.planner.mode' => 'classic', 'reasoning.enabled' => true,
            'reasoning.selective_validation' => true, 'kb.investigation.enabled' => false, 'ai.providers.openrouter.key' => 'fixture',
            'connector-api.ssrf.resolve_dns' => false]);
        app(TenantContext::class)->set('acme');
        Http::preventStrayRequests();
    }

    public function test_context_command_executes_document_and_api_queries_and_stops_before_composition(): void
    {
        [$user, $document, $chunk] = $this->sources();
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect([
            ['chunk_id' => $chunk->id, 'chunk_text' => self::POLICY, 'document' => $document->toArray()],
        ]), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $this->fakeDecisions(allowApi: true);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturnUsing(function ($provider, $system, $messages, $options) {
            $intent = $this->intent();
            if (isset($options['response_format']['json_schema']['schema']['properties']['memory_decisions'])) {
                $intent['memory_decisions'] = [];
            }

            return new AiResponse(json_encode($intent, JSON_THROW_ON_ERROR), 'fake', 'fixture');
        });
        $ai->shouldReceive('chatWithHistory')->twice()->andReturnUsing(function ($system, $messages, $options) {
            $this->assertSame('submit_agent_plan', $options['tool_choice']['function']['name']);
            $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            $actions = $input['completed_actions'] !== [] ? [] : [
                ['id' => 'policy', 'tool' => 'search_knowledge_base', 'arguments_json' => '{"query":"CASE-A rimborso"}', 'purpose' => 'Leggi la regola di rimborso'],
                ['id' => 'returns', 'tool' => 'gescat_returns', 'arguments_json' => '{}', 'purpose' => 'Leggi i resi in Gescat'],
            ];

            return $this->submission('submit_agent_plan', ['decision' => $actions === [] ? 'answer' : 'tools', 'actions' => $actions]);
        });
        $this->app->instance(AiManager::class, $ai);

        $this->assertSame(0, Artisan::call('agent:context', ['question' => self::QUESTION, '--tenant' => 'acme',
            '--project' => 'returns', '--user' => (string) $user->id, '--locale' => 'it']));
        $snapshot = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($snapshot['ready']);
        $this->assertSame(self::QUESTION, $snapshot['question']);
        $this->assertSame(self::POLICY, data_get($snapshot, 'evidence.documents.0.evidence.0.content'));
        $this->assertSame(self::RECORDS, data_get($snapshot, 'evidence.api_tools.0.result'));
        $this->assertSame(['CASE-A rimborso'], array_column(array_column($snapshot['queries'], 'arguments'), 'query'));
        $this->assertEqualsCanonicalizing(['api', 'knowledge'], array_column($snapshot['queries'], 'kind'));
        $this->assertArrayNotHasKey('answer', $snapshot);
        $this->assertArrayNotHasKey('response', AgentRun::query()->sole()->result_json);
        $this->assertSame(0, Conversation::query()->count());
        Http::assertSent(fn ($request) => $request->url() === self::ENDPOINT);
        $this->assertSame(0, Artisan::call('agent:context', ['--resume' => $snapshot['run_id'],
            '--tenant' => 'acme', '--user' => (string) $user->id]));
        $this->assertSame($snapshot, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_answer_command_uses_only_injected_context_and_keeps_explanation_and_widget(): void
    {
        [$user, $document, $chunk] = $this->sources();
        $snapshot = $this->savedContext($user, $document, $chunk->id);
        $file = tempnam(sys_get_temp_dir(), 'agent-context-');
        try {
            // The answer is tested independently, without calling agent:context.
            $this->app->instance(ChatRetrievalService::class, Mockery::mock(ChatRetrievalService::class)->shouldNotReceive('retrieve')->getMock());
            $this->fakeDecisions(allowApi: false);
            $ai = Mockery::mock(AiManager::class);
            $ai->shouldReceive('chatWithHistory')->once()->andReturnUsing(function ($system, $messages, $options) {
                $this->assertSame('submit_agent_answer', $options['tool_choice']['function']['name']);
                $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(self::QUESTION, $input['question']);
                $this->assertStringContainsString('Stavamo parlando del reso CASE-A.', $input['turn_context']);
                $this->assertTrue($input['table_artifact_available']);
                $passage = $input['evidence']['documents'][0]['evidence'][0];

                return $this->submission('submit_agent_answer', ['completeness' => 'complete',
                    'claims' => [['text' => self::EXPLANATION, 'quote' => self::POLICY,
                        'subquestion_id' => 1, 'source_choice' => $passage['source_choice']]],
                    'render_table' => true, 'requires_selection' => false, 'limitations' => []]);
            });
            $this->app->instance(AiManager::class, $ai);

            file_put_contents($file, AgentConsoleContext::json($snapshot));
            $this->assertSame(0, Artisan::call('agent:answer', ['context' => $file]));
            $answer = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(self::EXPLANATION, $answer['answer']);
            $this->assertSame($document->id, data_get($answer, 'citations.0.document_id'));
            $this->assertSame('ui-data-table', data_get($answer, 'artifact.component_type'));
            $this->assertSame('awaiting_inspection', data_get($answer, 'artifact.rows.0.values.status'));
            $this->assertSame(data_get($answer, 'artifact.source_execution_id'), data_get($answer, 'tool_sources.0.execution_id'));
            $this->assertSame(1, AgentRun::query()->sole()->toolExecutions()->count());
            $this->assertSame(0, Conversation::query()->count());
            Http::assertNotSent(fn ($request) => $request->url() === self::ENDPOINT);

            $tampered = $snapshot;
            $tampered['evidence']['api_tools'][0]['result']['records'][0]['status'] = 'paid';
            file_put_contents($file, AgentConsoleContext::json($tampered));
            $this->assertSame(2, Artisan::call('agent:answer', ['context' => $file]));
            $this->assertStringContainsString('does not match', Artisan::output());

            ProjectMembership::query()->forTenant('acme')->where('user_id', $user->id)->delete();
            file_put_contents($file, AgentConsoleContext::json($snapshot));
            $this->assertSame(2, Artisan::call('agent:answer', ['context' => $file]));
            $this->assertStringContainsString('has no access to the project', Artisan::output());
        } finally {
            unlink($file);
        }
    }

    private function sources(): array
    {
        $user = User::create(['name' => 'QA', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('fixture'), 'is_active' => true]);
        ProjectMembership::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'returns', 'role' => 'member']);
        $connector = ApiConnector::create(['tenant_id' => 'acme', 'project_key' => 'returns', 'name' => 'Gescat', 'is_active' => true]);
        ApiRoute::create(['tenant_id' => 'acme', 'api_connector_id' => $connector->id, 'project_key' => 'returns', 'name' => 'gescat_returns',
            'slug' => 'gescat_returns', 'http_method' => 'GET', 'url' => self::ENDPOINT, 'mode' => 'tool', 'status' => 'active',
            'endpoint_type' => 'list', 'items_path' => 'records', 'identity_fields' => ['id'],
            'tool_definition' => ['name' => 'gescat_returns', 'description' => 'List returns in Gescat', 'input_schema' => ['type' => 'object']]]);
        $hash = hash('sha256', self::POLICY);
        $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'returns', 'source_type' => 'markdown',
            'title' => 'Procedura resi CASE-A', 'source_path' => 'returns.md', 'document_hash' => $hash, 'status' => 'active']);
        $chunk = $document->chunks()->create(['tenant_id' => 'acme', 'project_key' => 'returns',
            'chunk_order' => 0, 'chunk_text' => self::POLICY, 'chunk_hash' => $hash]);

        return [$user, $document, $chunk];
    }

    private function savedContext(User $user, KnowledgeDocument $document, int $chunkId): array
    {
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'returns', 'chat', 'user', (string) $user->id, 'it', 'UTC');
        $run = app(\App\Agent\AgentRunDispatcher::class)->create($context, ['question' => self::QUESTION, 'console_context' => true,
            'session_evidence' => true, 'console_dialogue' => [['role' => 'user', 'text' => 'Stavamo parlando del reso CASE-A.']]], ['user_id' => $user->id]);
        $tool = app(AgentToolRegistry::class)->forResearch($run, $context)['gescat_returns'];
        $execution = $run->toolExecutions()->create(['logical_index' => 1, 'tool_name' => $tool->name, 'tool_kind' => 'api',
            'status' => 'completed', 'arguments_json' => [], 'result_meta_json' => ['result' => self::RECORDS]]);
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument(['document_id' => $document->id, 'title' => $document->title, 'evidence' => [
            ['chunk_id' => $chunkId, 'content' => self::POLICY, 'evidence_hash' => hash('sha256', self::POLICY)],
        ]]);
        $evidence->addToolResult($tool, [], self::RECORDS, $execution->id);
        $run->forceFill(['status' => AgentRun::STATUS_COMPLETED, 'completed_at' => now(), 'result_json' => ['phase' => 'context',
            'decision' => 'answer', 'stop_reason' => null, 'question_understanding' => $this->intent() + ['available' => true],
            'evidence' => $evidence->jsonSerialize()]])->save();

        return app(AgentConsoleContext::class)->export($run);
    }

    private function intent(): array
    {
        $focus = ['topic' => 'return', 'identifiers' => ['CASE-A'], 'aspect' => 'status', 'fields' => ['status']];

        return ['language' => 'it', 'intent' => self::QUESTION, 'action' => 'research', 'kb_queries' => ['CASE-A rimborso'],
            'mentions' => [['text' => 'CASE-A', 'type' => 'identifier']], 'context_mode' => 'new', 'requires_research' => true,
            'references_previous_turn' => false, 'transition' => 'new', 'resolved_references' => [], 'needs_clarification' => false,
            'clarification' => '', 'requires_composition' => true, 'focus' => $focus,
            'deliverable' => ['kind' => 'answer', 'quantity' => null, 'freshness' => 'current'], 'subquestions' => [
                [...$focus, 'question' => 'Quali resi CASE-A risultano oggi in Gescat?', 'context_mode' => 'new', 'requires_research' => true,
                    'kb_queries' => [], 'deliverable' => ['kind' => 'answer', 'quantity' => null, 'freshness' => 'current']],
                [...$focus, 'aspect' => 'refund_policy', 'fields' => [], 'question' => 'Cosa prevede la regola del rimborso per CASE-A?',
                    'context_mode' => 'new', 'requires_research' => true, 'kb_queries' => ['CASE-A rimborso'],
                    'deliverable' => ['kind' => 'procedure', 'quantity' => null, 'freshness' => 'source_context']],
            ]];
    }

    private function fakeDecisions(bool $allowApi): void
    {
        Http::fake(function ($request) use ($allowApi) {
            if ($request->url() === self::ENDPOINT) {
                $this->assertTrue($allowApi, 'Composition must not call a connector.');

                return Http::response(self::RECORDS);
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
    }

    private function submission(string $name, array $arguments): AiResponse
    {
        return new AiResponse('', 'fake', 'fixture', toolCalls: [['name' => $name, 'arguments' => $arguments]]);
    }
}
