<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentAnswer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentResultProjector;
use App\Agent\AgentRetrievalFiltersFactory;
use App\Agent\AgentRunDeadline;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Contracts\AgentRunHandler;
use App\Http\Controllers\Api\AgentMessageController;
use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpAppInstance;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AgentMessageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        Route::middleware('api')->post('/test-conversations/{conversation}/messages/agent', [AgentMessageController::class, 'store']);
    }

    public function test_authenticated_turn_is_persisted_and_dispatched_as_a_durable_run(): void
    {
        Queue::fake();
        app(TenantContext::class)->set('acme');
        $user = $this->user('agent-chat@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'crm',
            'role' => 'member',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'title' => 'Ordini',
            'project_key' => 'crm',
        ]);

        $response = $this->actingAs($user)->postJson(
            '/test-conversations/'.$conversation->id.'/messages/agent',
            [
                'content' => 'Dammi gli ordini di Tizio',
                'filters' => ['project_keys' => ['other-project'], 'languages' => ['it']],
                'live_sources' => ['api' => [], 'mcp' => ['mcp:hubhive']],
            ],
        );

        $response->assertAccepted()
            ->assertJsonPath('status', AgentRun::STATUS_QUEUED)
            ->assertJsonPath('locale', 'it-IT')
            ->assertJsonPath('user_message.content', 'Dammi gli ordini di Tizio');
        $run = AgentRun::query()->sole();
        $this->assertSame($conversation->id, $run->conversation_id);
        $this->assertSame($user->id, $run->user_id);
        $this->assertSame('other-project', data_get($run->input_json, 'filters.project_keys.0'));
        $this->assertSame([], data_get($run->input_json, 'live_sources.api'));
        $this->assertSame(['mcp:hubhive'], data_get($run->input_json, 'live_sources.mcp'));
        $this->assertSame($run->run_id, data_get($conversation->messages()->sole()->metadata, 'agent_run_id'));
        Queue::assertPushed(ExecuteAgentRunJob::class, fn ($job): bool => $job->agentRunId === $run->id
            && $job->tenantId === 'acme');

        $filters = app(AgentRetrievalFiltersFactory::class)->forRun(
            $run,
            AgentExecutionContext::fromArray([
                'run_id' => $run->run_id,
                'tenant_id' => $run->tenant_id,
                'project_key' => $run->project_key,
                'channel' => $run->channel,
                'actor_type' => $run->actor_type,
                'actor_id' => $run->actor_id,
                'locale' => $run->locale,
                'timezone' => $run->timezone,
            ]),
        );
        $this->assertSame(['crm'], $filters->projectKeys, 'Persisted client filters cannot broaden the conversation project.');
        $this->assertSame(['it'], $filters->languages);
    }

    public function test_depth_defaults_to_3_when_the_client_omits_it(): void
    {
        Queue::fake();
        app(TenantContext::class)->set('acme');
        $user = $this->user('agent-depth-default@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm', 'role' => 'member',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm',
        ]);

        $this->actingAs($user)->postJson(
            '/test-conversations/'.$conversation->id.'/messages/agent',
            ['content' => 'Parlami del pannello delle notifiche push'],
        )->assertAccepted();

        $this->assertSame(3, data_get(AgentRun::query()->sole()->input_json, 'depth'));
    }

    public static function documentFollowUps(): array
    {
        return [
            'planner finds coverage in the session' => [false],
            'sources-only follow-up discards repeated answer' => [true],
            'source examples marked as composition discard the unchanged reply' => [true, true],
            'requested formatting may reuse the same facts' => [true, true, true],
            'source context answers the current request directly' => [true, true, false, true],
            'a gap in retained evidence starts a targeted search' => [true, true, false, true, true],
            'coverage verification rejects a generic reply marked complete' => [true, true, false, true, true, true],
        ];
    }

    #[DataProvider('documentFollowUps')]
    public function test_chat_reuses_the_rest_of_a_short_document_for_a_follow_up(bool $contextOnly, bool $composition = false,
        bool $formatOnly = false, bool $probe = false, bool $searchMissing = false, bool $coverageGap = false): void
    {
        Queue::fake();
        config(['agent.waves.enabled' => true, 'agent.concurrent_session' => false, 'reasoning.enabled' => true, 'reasoning.selective_validation' => $coverageGap,
            'agent.grounding.enabled' => false, 'kb.investigation.enabled' => false]);
        app(TenantContext::class)->set('acme');
        $user = $this->user('session-document@example.com');
        ProjectMembership::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm', 'role' => 'member']);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm']);
        $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'crm', 'source_type' => 'pdf',
            'title' => 'Manuale resi', 'source_path' => 'manuals/resi.pdf', 'mime_type' => 'application/pdf', 'status' => 'active',
            'document_hash' => hash('sha256', 'document'), 'version_hash' => hash('sha256', 'version')]);
        $example = 'Per un reso in negozio, il cliente consegna il modulo compilato allo sportello.';
        $pages = ['La procedura inizia con la richiesta di reso.', 'Il cliente identifica il collo.',
            $searchMissing ? 'Il modulo contiene i riferimenti del collo.' : $example];
        foreach ($pages as $index => $page) {
            $document->chunks()->create(['tenant_id' => 'acme', 'project_key' => 'crm', 'chunk_order' => $index,
                'chunk_text' => $page, 'chunk_hash' => hash('sha256', $page)]);
        }
        $first = $document->chunks()->orderBy('chunk_order')->firstOrFail();
        $search = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $search->shouldReceive('retrieve')->once()->withArgs(fn (string $query) => $query === 'procedura resi')->andReturn(new SearchResult(collect([
            ['chunk_id' => $first->id, 'chunk_text' => $first->chunk_text, 'document' => $document->toArray()],
        ]), collect(), collect()));
        if ($searchMissing) {
            $extra = $document->replicate();
            $extra->forceFill(['title' => 'Esempi resi', 'source_path' => 'manuals/esempi.pdf',
                'document_hash' => hash('sha256', 'examples'), 'version_hash' => hash('sha256', 'examples-version')])->save();
            $chunk = $extra->chunks()->create(['tenant_id' => 'acme', 'project_key' => 'crm', 'chunk_order' => 0,
                'chunk_text' => $example, 'chunk_hash' => hash('sha256', $example)]);
            $search->shouldReceive('retrieve')->once()->withArgs(fn (string $query) => $query === 'esempio reso in negozio')->andReturn(new SearchResult(collect([
                ['chunk_id' => $chunk->id, 'chunk_text' => $chunk->chunk_text, 'document' => $extra->toArray()],
            ]), collect(), collect()));
        }
        $this->app->instance(ChatRetrievalService::class, $search);

        $questions = ['Come si avvia un reso?', $formatOnly ? 'Metti la risposta precedente in un elenco.' : 'Fammi un esempio dal testo che hai trovato.'];
        if ($coverageGap) {
            config(['ai.providers.openrouter.key' => 'test']);
            Http::preventStrayRequests();
            Http::fake(function ($request) use ($questions, $pages) {
                $answers = [];
                foreach ($request['questions'] as $key => $criterion) {
                    if ($criterion['type'] === 'noul') {
                        $repeatedDefinition = $key === 'covered' && data_get($request->data(), 'state.question') === $questions[1]
                            && data_get($request->data(), 'state.answer.0') === $pages[0];
                        $answers[$key] = ['type' => 'noul', 'noul' => $repeatedDefinition ? .66 : .99];
                    } else {
                        $choice = array_key_first($criterion['criteria']);
                        $answers[$key] = ['type' => 'choice', 'choice' => $choice, 'confidence' => 1,
                            'probabilities' => array_replace(array_fill_keys(array_keys($criterion['criteria']), 0), [$choice => 1])];
                    }
                }
                return Http::response(['id' => 'coverage', 'model' => 'jev-test', 'answers' => $answers]);
            });
        }
        $secondAnswer = $formatOnly ? '- '.$pages[0] : $example;
        $shouldRetry = $contextOnly && ! $formatOnly && ! $probe;
        $synthesized = [];
        $ai = Mockery::mock(AiManager::class);
        $modelCalls = $searchMissing ? 7 : ($formatOnly || $probe ? 4 : 5);
        $ai->shouldReceive('chatWithHistory')->times($modelCalls)->andReturnUsing(function (string $system, array $messages, array $options) use ($questions, $pages, $contextOnly, $secondAnswer, $shouldRetry, $probe, $searchMissing, $coverageGap, &$synthesized) {
            $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            $tool = $options['tool_choice']['function']['name'];
            if ($tool === 'submit_agent_plan') {
                if ($input['question'] === $questions[0]) {
                    // The second plan repeats a successful lookup. The host
                    // should synthesize from that result without calling again.
                    return new AiResponse(content: '', provider: 'fake', model: 'fake-agent', toolCalls: [['name' => $tool,
                        'arguments' => ['decision' => 'tools', 'actions' => [['id' => $input['completed_actions'] === [] ? 'manual' : 'manual_again',
                            'tool' => 'search_knowledge_base', 'arguments' => ['query' => 'procedura resi'], 'depends_on' => [], 'purpose' => 'Leggi il manuale dei resi']]]]]);
                }
                $this->assertSame(implode("\n\n", $pages), data_get($input, 'collected_evidence.documents.0.evidence.0.content'));
                if ($searchMissing) {
                    $state = json_decode($input['turn_context'], true, flags: JSON_THROW_ON_ERROR);
                    if ($coverageGap) {
                        $this->assertFalse($state['session_context_gap']['coverage_sufficient']);
                    } else {
                        $this->assertContains('Manca un esempio di reso in negozio.', $state['session_context_gap']['limitations']);
                    }
                    if ($input['completed_actions'] === []) {
                        return new AiResponse(content: '', provider: 'fake', model: 'fake-agent', toolCalls: [['name' => $tool,
                            'arguments' => ['decision' => 'tools', 'actions' => [['id' => 'example', 'tool' => 'search_knowledge_base',
                                'arguments' => ['query' => 'esempio reso in negozio'], 'depends_on' => [], 'purpose' => 'Trova l’esempio mancante']]]]]);
                    }
                } else {
                    $this->assertFalse($contextOnly, 'A covered or sources-only follow-up must not plan more searches.');
                }
                return new AiResponse(content: '', provider: 'fake', model: 'fake-agent',
                    toolCalls: [['name' => $tool, 'arguments' => ['decision' => 'answer', 'actions' => []]]]);
            }

            $passage = data_get($input, 'evidence.documents.0.evidence.0');
            $this->assertSame(implode("\n\n", $pages), $passage['content']);
            $this->assertTrue($passage['complete_source']);
            $this->assertSame('submit_agent_answer', $tool);
            $this->assertStringContainsString('This submission is the final response', $system);
            $this->assertArrayNotHasKey('supported_artifacts', $input);
            $firstAnswer = $input['question'] === $questions[0];
            if (! $firstAnswer && $contextOnly && ! isset($input['session_context_gap'])) {
                $this->assertStringContainsString('Follow-up from previously collected sources', $system);
                $this->assertStringContainsString('start with an actual scenario', $system);
            }
            $retry = in_array($questions[1], $synthesized, true);
            if ($retry && $shouldRetry) {
                $this->assertStringContainsString('only repeated claims already communicated', $system);
            }
            $synthesized[] = $input['question'];
            if (! $firstAnswer && $probe) {
                $navigation = json_decode($input['turn_context'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('already_communicated', $navigation['reasoning']);
                $this->assertStringNotContainsString($pages[0], $input['turn_context']);
            }
            $trial = $input['stop_reason'] === 'context_answer_attempt';
            if ($searchMissing && $trial && ! $coverageGap) {
                return new AiResponse(content: '', provider: 'fake', model: 'fake-agent', toolCalls: [['name' => $tool,
                    'arguments' => ['completeness' => 'insufficient', 'claims' => [], 'limitations' => ['Manca un esempio di reso in negozio.'],
                        'requires_selection' => false, 'render_table' => false]]]);
            }
            $text = $firstAnswer || ($shouldRetry && ! $retry) || ($coverageGap && $trial) ? $pages[0] : $secondAnswer;
            $source = $input['evidence']['documents'][$searchMissing && ! $firstAnswer && ! $trial ? 1 : 0];

            return new AiResponse(content: '', provider: 'fake', model: 'fake-agent', toolCalls: [['name' => $tool,
                'arguments' => ['completeness' => 'complete', 'claims' => [['text' => $text, 'quote' => $source['evidence'][0]['content'],
                    'document_id' => $source['document_id'], 'tool_execution_id' => null, 'evidence_hash' => $source['evidence'][0]['evidence_hash']]],
                    'limitations' => [], 'requires_selection' => false, 'render_table' => false]]]);
        });
        $this->app->instance(AiManager::class, $ai);

        foreach ($questions as $index => $question) {
            $response = $this->actingAs($user)->postJson('/test-conversations/'.$conversation->id.'/messages/agent', ['content' => $question]);
            $response->assertAccepted();
            $run = AgentRun::query()->where('run_id', $response->json('run_id'))->firstOrFail();
            $this->assertTrue($run->usesSessionEvidence());
            $run->update(['result_json' => ['question_understanding' => ['available' => true, 'language' => 'it', 'intent' => $question,
                'action' => $index === 0 ? 'research' : 'refine', 'transition' => $index === 0 ? 'new' : 'continue',
                'references_previous_turn' => $index !== 0, 'requires_research' => $index === 0 || ! $contextOnly,
                'requires_composition' => $index !== 0 && $composition,
                'follow_up_target' => $index === 0 || $formatOnly ? 'previous_reply' : ($probe || ! $contextOnly ? 'sources' : 'collected_only')]]]);
            if ($searchMissing && $index === 1) {
                config(['queue.default' => 'redis']);
                app(AgentRunDeadline::class)->within($run, now(), 120, fn () => app(AgentRunHandler::class)->handle($run));
                $this->assertSame(AgentRun::STATUS_QUEUED, $run->fresh()->status);
                $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
                $this->assertSame(0, $run->toolExecutions()->count());
                $this->assertCount(2, Queue::pushed(ExecuteAgentRunJob::class, fn (ExecuteAgentRunJob $job): bool => $job->agentRunId === $run->id));
            }
            app(AgentRunHandler::class)->handle($run);
            $this->assertSame(AgentRun::STATUS_COMPLETED, $run->fresh()->status, 'Turn '.($index + 1));
            $this->assertSame($index === 0 || $searchMissing ? 1 : 0, $run->toolExecutions()->where('status', 'completed')->count());
            $secondStop = $searchMissing ? null : ($probe ? 'context_answer_attempt' : ($contextOnly ? 'context_only' : null));
            $this->assertSame($index === 0 ? 'lookups_already_observed' : $secondStop,
                data_get($run->fresh()->result_json, 'stop_reason'));
            $this->assertTrue($run->events()->where('type', 'run.completed')->exists());
        }

        $this->assertSame($shouldRetry || $searchMissing ? [...$questions, $questions[1]] : $questions, $synthesized);
        $this->assertSame([$pages[0], $secondAnswer], $conversation->messages()->where('role', 'assistant')->orderBy('id')->pluck('content')->all());
    }

    public static function queuedQuestionCoverage(): array
    {
        return ['field already collected' => [true], 'field needs its own lookup' => [false]];
    }

    #[DataProvider('queuedQuestionCoverage')]
    public function test_session_answers_from_retained_evidence_while_research_waits_in_order(bool $fieldIncluded): void
    {
        Queue::fake();
        config(['agent.concurrent_session' => true, 'agent.waves.enabled' => true, 'reasoning.enabled' => true,
            'reasoning.selective_validation' => false, 'agent.grounding.enabled' => false, 'kb.investigation.enabled' => false]);
        app(TenantContext::class)->set('acme');
        $user = $this->user('interactive-session@example.com');
        ProjectMembership::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm', 'role' => 'member']);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm']);
        $questions = ['Come si avvia un reso?', 'Come si avvia un reso e chi rimborsa?', 'Dove si invia il modulo?', 'Quanto dura la finestra del reso?'];
        $facts = ['Compila il modulo di reso.', 'Il negozio emette il rimborso.', 'Invia il modulo allo sportello.', 'La finestra è di trenta giorni.'];
        $queries = ['procedura reso', 'chi emette rimborso', 'invio modulo'];
        $searches = [];
        $search = Mockery::mock(ChatRetrievalService::class)->makePartial();
        foreach ($queries as $index => $query) {
            if ($index === 2 && $fieldIncluded) {
                continue;
            }
            $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'crm', 'source_type' => 'pdf',
                'title' => $query, 'source_path' => "manuals/{$index}.pdf", 'mime_type' => 'application/pdf', 'status' => 'active',
                'document_hash' => hash('sha256', $query), 'version_hash' => hash('sha256', $index.$query)]);
            foreach (match ($index) { 0 => [$facts[0], $facts[3]], 1 => $fieldIncluded ? [$facts[1], $facts[2]] : [$facts[1]], default => [$facts[$index]] } as $order => $page) {
                $document->chunks()->create(['tenant_id' => 'acme', 'project_key' => 'crm', 'chunk_order' => $order,
                    'chunk_text' => $page, 'chunk_hash' => hash('sha256', $page)]);
            }
            $chunk = $document->chunks()->orderBy('chunk_order')->firstOrFail();
            $search->shouldReceive('retrieve')->once()->withArgs(fn (string $actual) => $actual === $query)
                ->andReturnUsing(function () use ($chunk, $document, $query, &$searches): SearchResult {
                    $searches[] = $query;

                    return new SearchResult(collect([['chunk_id' => $chunk->id, 'chunk_text' => $chunk->chunk_text,
                        'document' => $document->toArray()]]), collect(), collect());
                });
        }
        $this->app->instance(ChatRetrievalService::class, $search);
        $answers = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->andReturnUsing(function (string $system, array $messages, array $options) use ($questions, $facts, $queries, &$answers): AiResponse {
            $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            $tool = $options['tool_choice']['function']['name'];
            $index = array_search($input['question'], $questions, true);
            $trial = ($input['stop_reason'] ?? null) === 'context_answer_attempt';
            $gap = 'Serve sapere chi emette il rimborso.';
            if ($tool === 'submit_agent_plan') {
                $this->assertLessThan(3, $index);
                if ($index === 1) {
                    $this->assertContains($gap, data_get(json_decode($input['turn_context'], true), 'session_context_gap.limitations'));
                }
                $arguments = $input['completed_actions'] === [] ? ['decision' => 'tools', 'actions' => [[
                    'id' => 'manual', 'tool' => 'search_knowledge_base', 'arguments' => ['query' => $queries[$index]],
                    'depends_on' => [], 'purpose' => 'Recupera il dato richiesto',
                ]]] : ['decision' => 'answer', 'actions' => []];
            } else {
                $this->assertSame('submit_agent_answer', $tool);
                $answers[] = [$index, $trial];
                $partial = $index === 1 && $trial;
                $claims = [];
                foreach (match (true) {
                    $partial => [$facts[0]],
                    $index === 1 => [$facts[0], $facts[1]],
                    $index === 2 && $trial && ! str_contains(json_encode($input['evidence'], JSON_UNESCAPED_UNICODE), $facts[2]) => [],
                    default => [$facts[$index]],
                } as $fact) {
                    $source = collect($input['evidence']['documents'])->first(fn (array $source) => str_contains($source['evidence'][0]['content'], $fact));
                    $this->assertNotNull($source, $fact);
                    $claims[] = ['text' => $fact, 'quote' => $fact, 'document_id' => $source['document_id'],
                        'tool_execution_id' => null, 'evidence_hash' => $source['evidence'][0]['evidence_hash']];
                }
                $arguments = ['completeness' => $partial ? 'partial' : ($claims === [] ? 'insufficient' : 'complete'),
                    'claims' => $claims, 'limitations' => $partial ? [$gap] : [], 'requires_selection' => false, 'render_table' => false];
            }

            return new AiResponse(content: '', provider: 'fake', model: 'fake-agent', toolCalls: [['name' => $tool, 'arguments' => $arguments]]);
        });
        $this->app->instance(AiManager::class, $ai);

        $send = function (int $index) use ($questions, $user, $conversation): AgentRun {
            $response = $this->actingAs($user)->postJson('/test-conversations/'.$conversation->id.'/messages/agent',
                ['content' => $questions[$index]])->assertAccepted()->assertJsonPath('concurrent_session', true);
            $run = AgentRun::query()->where('run_id', $response->json('run_id'))->firstOrFail();
            Queue::assertPushedOn('agent-session', ExecuteAgentRunJob::class, fn ($job) => $job->agentRunId === $run->id);
            $run->update(['result_json' => ['question_understanding' => ['available' => $index !== 3, 'language' => 'it',
                // A malformed classifier response still leaves the actual question and sources.
                'intent' => $questions[$index], 'action' => 'research', 'requires_research' => true,
                // The saved classification can wrongly point to an earlier reply even on the first turn.
                'follow_up_target' => $index === 0 ? 'previous_reply' : 'sources']]]);

            return $run;
        };
        $work = fn (AgentRun $run) => app(AgentRunHandler::class)->handle($run->fresh());
        $phase = fn (AgentRun $run) => data_get($run->fresh()->result_json, 'session_research.state');
        $seed = $send(0);
        $work($seed);
        $this->assertSame('reassess', $phase($seed));
        $work($seed);
        $this->assertSame('active', $phase($seed));
        $this->assertSame([], $searches);
        $this->assertSame('research.queued_without_answer', $seed->events()->where('type', 'research.queued')->sole()->message_key);
        Queue::assertPushedOn('agent', ExecuteAgentRunJob::class, fn ($job) => $job->agentRunId === $seed->id);
        \Illuminate\Support\Facades\Context::scope(fn () => $work($seed), hidden: ['agent.session_stage' => 'conversation']);
        $this->assertSame([], $searches, 'A repeated conversational delivery cannot collect sources.');
        $work($seed);
        $this->assertSame('ready', $phase($seed));
        \Illuminate\Support\Facades\Context::scope(fn () => $work($seed), hidden: ['agent.session_stage' => 'active']);
        $this->assertSame([], $answers);
        $work($seed);

        $partial = $send(1);
        $queued = $send(2);
        $covered = $send(3);
        $work($queued);
        $this->assertSame('waiting', $phase($queued), 'An older assessment retains priority.');
        $work($partial);
        $this->assertSame('reassess', $phase($partial));
        $work($partial);
        $this->assertSame('active', $phase($partial));
        $this->assertSame($queries[0], end($searches));
        $preview = $conversation->messages()->where('metadata->session_interim', true)->sole();
        $this->assertSame('research.queued_after_partial', $partial->events()->where('type', 'research.queued')->sole()->message_key);
        $this->assertStringContainsString($facts[0], $preview->content);
        $this->assertSame(data_get($partial->input_json, 'user_message_id'), data_get($preview->metadata, 'reply_to_user_message_id'));
        $work($covered);
        $this->assertSame('completed', $covered->fresh()->status);
        $this->assertSame($facts[3], $conversation->messages()->where('agent_run_id', $covered->id)->sole()->content);
        $this->assertSame([$queries[0]], $searches);
        $activity = app(\App\Services\Chat\ConversationActivityService::class)->list($user->id, 'acme')[0];
        $this->assertSame([$partial->run_id, $queued->run_id], array_column($activity['runs'], 'run_id'));
        $this->assertSame($partial->run_id, $activity['run']['run_id']);

        $work($partial);
        $this->assertSame('ready', $phase($partial));
        $this->assertSame('waiting', $phase($queued));
        $this->assertFalse($conversation->messages()->where('agent_run_id', $partial->id)->exists());
        $work($partial);
        $this->assertStringContainsString($facts[1], $conversation->messages()->where('agent_run_id', $partial->id)->sole()->content);
        $this->assertSame('reassess', $phase($queued));
        $work($queued);
        if (! $fieldIncluded) {
            $this->assertSame('active', $phase($queued));
            $work($queued);
            $work($queued);
        }
        $this->assertTrue($queued->fresh()->isTerminal());
        $work($queued); // Redelivery after the final answer.
        $this->assertSame($fieldIncluded ? 0 : 1, $queued->toolExecutions()->count());
        $this->assertSame($facts[2], $conversation->messages()->where('agent_run_id', $queued->id)->sole()->content);
        $this->assertSame($fieldIncluded ? array_slice($queries, 0, 2) : $queries, $searches);
        $this->assertSame([[0, false], [2, true], [1, true], [1, true], [3, true], [1, false], [2, true],
            ...$fieldIncluded ? [] : [[2, false]]], $answers);
    }

    public function test_an_explicit_depth_is_persisted_on_the_run(): void
    {
        Queue::fake();
        app(TenantContext::class)->set('acme');
        $user = $this->user('agent-depth-explicit@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm', 'role' => 'member',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm',
        ]);

        $this->actingAs($user)->postJson(
            '/test-conversations/'.$conversation->id.'/messages/agent',
            ['content' => 'Analizza a fondo il modulo di fatturazione', 'depth' => 5],
        )->assertAccepted();

        $this->assertSame(5, data_get(AgentRun::query()->sole()->input_json, 'depth'));
    }

    public function test_an_out_of_range_depth_is_rejected(): void
    {
        app(TenantContext::class)->set('acme');
        $user = $this->user('agent-depth-invalid@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm', 'role' => 'member',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm',
        ]);

        $this->actingAs($user)->postJson(
            '/test-conversations/'.$conversation->id.'/messages/agent',
            ['content' => 'Domanda qualsiasi', 'depth' => 6],
        )->assertUnprocessable()->assertJsonValidationErrors('depth');

        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_authorized_mcp_app_context_is_attached_to_the_durable_run(): void
    {
        Queue::fake();
        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.runtime_mode', 'active');
        config()->set('connector-mcp.apps.advanced_enabled', true);
        app(TenantContext::class)->set('acme');
        app(ConnectorTenantContext::class)->set('acme');
        $user = $this->user('agent-mcp-app@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'crm',
            'role' => 'member',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'title' => 'MCP App',
            'project_key' => 'crm',
        ]);
        $instance = $this->mcpAppInstance($user, $conversation);

        $this->actingAs($user)->postJson(
            '/test-conversations/'.$conversation->id.'/messages/agent',
            [
                'content' => 'Continua dalla selezione.',
                'mcp_app_id' => $instance->public_id,
            ],
        )->assertAccepted();

        $this->assertSame($instance->public_id, data_get(AgentRun::query()->sole()->input_json, 'mcp_app_id'));
    }

    public function test_table_selection_is_resolved_from_the_server_artifact_and_attached_to_the_run(): void
    {
        Queue::fake();
        app(TenantContext::class)->set('acme');
        $user = $this->user('agent-selection@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'crm',
            'role' => 'member',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'title' => 'Disambiguation',
            'project_key' => 'crm',
        ]);
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Quale Riccardo Lorini vuoi usare?',
            'metadata' => [
                'agent_artifact' => [
                    'component_type' => 'ui-data-table',
                    'interaction_mode' => 'selection',
                    'source_execution_id' => 44,
                    'tool' => 'search-customers',
                    'columns' => [
                        ['key' => 'id', 'label' => 'ID'],
                        ['key' => 'email', 'label' => 'Email'],
                    ],
                    'rows' => [
                        [
                            'key' => '101',
                            'label' => 'Riccardo Lorini',
                            'values' => ['id' => 101, 'email' => 'first@example.test'],
                            'record' => ['id' => 101, 'email' => 'first@example.test'],
                        ],
                        [
                            'key' => '102',
                            'label' => 'Riccardo Lorini',
                            'values' => ['id' => 102, 'email' => 'second@example.test'],
                            'record' => ['id' => 102, 'email' => 'second@example.test'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)->postJson(
            '/test-conversations/'.$conversation->id.'/messages/agent',
            [
                'content' => 'Ho scelto Riccardo Lorini.',
                'selection' => ['message_id' => $assistant->id, 'row_key' => '102'],
            ],
        )->assertAccepted();

        $run = AgentRun::query()->sole();
        $this->assertSame(102, data_get($run->input_json, 'selection.record.id'));
        $this->assertSame(44, data_get($run->input_json, 'selection.source_execution_id'));
        $this->assertSame('search-customers', data_get($run->input_json, 'selection.tool'));
        $userMessage = $conversation->messages()->where('role', 'user')->sole();
        $this->assertSame(102, data_get($userMessage->metadata, 'agent_selection.record.id'));
        $this->assertSame('Email', data_get($userMessage->metadata, 'agent_selection.display.fields.1.label'));
        $this->assertSame('second@example.test', data_get($userMessage->metadata, 'agent_selection.display.fields.1.value'));
        $this->assertSame('Ho selezionato “Riccardo Lorini”.', $userMessage->content);
        $this->assertStringNotContainsString('```json', $userMessage->content);
        $this->assertStringNotContainsString('second@example.test', $userMessage->content);
        $this->assertStringContainsString('Ho selezionato questa riga:', data_get($run->input_json, 'question'));
        $this->assertStringContainsString('"id": 102', data_get($run->input_json, 'question'));
        $this->assertStringContainsString('"email": "second@example.test"', data_get($run->input_json, 'question'));
    }

    public function test_a_row_from_a_view_table_can_continue_the_conversation(): void
    {
        Queue::fake();
        app(TenantContext::class)->set('acme');
        $user = $this->user('agent-view-selection@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'crm',
            'role' => 'member',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'title' => 'Orders',
            'project_key' => 'crm',
        ]);
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Ecco gli ordini.',
            'metadata' => ['agent_artifact' => [
                'component_type' => 'ui-data-table',
                'interaction_mode' => 'view',
                'source_execution_id' => 55,
                'tool' => 'search-orders',
                'rows' => [[
                    'key' => 'I016426',
                    'label' => 'I016426',
                    'record' => ['id' => 16310, 'number' => 'I016426', 'status' => ['code' => 'CONF']],
                ]],
            ]],
        ]);

        $this->actingAs($user)->postJson(
            '/test-conversations/'.$conversation->id.'/messages/agent',
            [
                'content' => 'Approfondisci questo ordine.',
                'selection' => ['message_id' => $assistant->id, 'row_key' => 'I016426'],
            ],
        )->assertAccepted();

        $run = AgentRun::query()->sole();
        $this->assertSame(16310, data_get($run->input_json, 'selection.record.id'));
        $this->assertStringContainsString('"number": "I016426"', data_get($run->input_json, 'question'));
        $this->assertStringContainsString('"code": "CONF"', data_get($run->input_json, 'question'));
    }

    public function test_selection_cannot_reference_an_artifact_from_another_conversation(): void
    {
        Queue::fake();
        app(TenantContext::class)->set('acme');
        $user = $this->user('agent-selection-scope@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'crm',
            'role' => 'member',
        ]);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm']);
        $other = Conversation::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm']);
        $foreignMessage = $other->messages()->create([
            'role' => 'assistant',
            'content' => 'Choose',
            'metadata' => ['agent_artifact' => ['interaction_mode' => 'selection', 'rows' => [
                ['key' => '101', 'record' => ['id' => 101]],
            ]]],
        ]);

        $this->actingAs($user)->postJson(
            '/test-conversations/'.$conversation->id.'/messages/agent',
            [
                'content' => 'Scelgo questo.',
                'selection' => ['message_id' => $foreignMessage->id, 'row_key' => '101'],
            ],
        )->assertUnprocessable()->assertJsonValidationErrors('selection');

        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_terminal_projection_is_idempotent_and_keeps_agent_sources(): void
    {
        app(TenantContext::class)->set('acme');
        $user = $this->user('project-agent@example.com');
        $conversation = Conversation::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'title' => 'Ordini',
            'project_key' => 'crm',
        ]);
        $run = AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'acme',
            'project_key' => 'crm',
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_COMPLETED,
        ]);
        $answer = new AgentAnswer(
            answer: 'Ordine A-100 trovato.',
            locale: 'it-IT',
            completeness: 'complete',
            citations: [['document_id' => 12, 'title' => 'Policy']],
            toolSources: [['execution_id' => 55, 'tool' => 'get_orders']],
            artifact: [
                'component_type' => 'ui-data-table',
                'interaction_mode' => 'view',
                'rows' => [['key' => 'A-100']],
            ],
        );

        $projector = app(AgentResultProjector::class);
        $projector->project($run, $answer);
        $projector->project($run, $answer);

        $message = $conversation->messages()->sole();
        $this->assertSame($run->id, $message->agent_run_id);
        $this->assertSame('Ordine A-100 trovato.', $message->content);
        $this->assertSame(12, data_get($message->metadata, 'citations.0.document_id'));
        $this->assertSame('get_orders', data_get($message->metadata, 'tool_sources.0.tool'));
        $this->assertSame('ui-data-table', data_get($message->metadata, 'agent_artifact.component_type'));
    }

    public function test_search_stats_count_every_attempted_call_not_only_the_cited_ones(): void
    {
        app(TenantContext::class)->set('acme');
        $user = $this->user('search-stats-agent@example.com');
        $conversation = Conversation::create([
            'tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'crm',
        ]);
        $run = AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'acme',
            'project_key' => 'crm',
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_COMPLETED,
        ]);
        // The always-on initial retrieval is NOT an AgentToolExecution row
        // (it runs before the first planning iteration) — it is the "+1"
        // baseline search_stats.kb_searches always carries.
        $run->toolExecutions()->createMany([
            // A cascading follow-up KB search the planner issued mid-run.
            ['logical_index' => 1, 'tool_name' => 'search_knowledge_base', 'tool_kind' => 'knowledge', 'status' => 'completed'],
            // A failed attempt still counts — the agent DID try.
            ['logical_index' => 2, 'tool_name' => 'search_knowledge_base', 'tool_kind' => 'knowledge', 'status' => 'failed'],
            ['logical_index' => 3, 'tool_name' => 'get_orders', 'tool_kind' => 'api', 'status' => 'completed'],
            ['logical_index' => 4, 'tool_name' => 'reports.show', 'tool_kind' => 'mcp', 'status' => 'completed'],
            // A catalog (list_knowledge_documents) call — counts as a
            // document search too, same as 'knowledge', just by title.
            ['logical_index' => 5, 'tool_name' => 'list_knowledge_documents', 'tool_kind' => 'catalog', 'status' => 'completed'],
            // Never actually executed (a dependency failed to resolve) —
            // must NOT be counted as an attempt.
            ['logical_index' => 6, 'tool_name' => 'get_order_detail', 'tool_kind' => 'api', 'status' => 'skipped'],
        ]);
        $answer = new AgentAnswer(
            answer: 'Trovati i dati richiesti.',
            locale: 'it-IT',
            completeness: 'complete',
            citations: [],
            toolSources: [['execution_id' => 3, 'tool' => 'get_orders']],
        );

        app(AgentResultProjector::class)->project($run, $answer);

        $message = $conversation->messages()->sole();
        // 1 (initial) + 2 knowledge-kind + 1 catalog-kind AgentToolExecution rows.
        $this->assertSame(4, data_get($message->metadata, 'search_stats.kb_searches'));
        // 1 api + 1 mcp, completed; the skipped one is excluded.
        $this->assertSame(2, data_get($message->metadata, 'search_stats.tool_calls'));
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'Agent user',
            'email' => $email,
            'password' => Hash::make('secret-pass-123'),
            'locale' => 'it-IT',
        ]);
    }

    private function mcpAppInstance(User $user, Conversation $conversation): McpAppInstance
    {
        $server = McpServerDefinition::query()->create([
            'tenant_id' => 'acme',
            'name' => 'Agent MCP App',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://agent-app.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $connection = McpConnection::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Agent MCP App',
            'status' => McpConnection::STATUS_ACTIVE,
        ]);
        $tool = McpConnectionTool::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => 'reports.show',
            'local_name' => 'agent_mcp_app_reports_show_12345678',
            'input_schema_json' => ['type' => 'object'],
            'risk' => 'read',
            'policy' => 'disabled',
            'enabled' => false,
            'confirmation_required' => false,
        ]);

        return McpAppInstance::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_connection_id' => $connection->getKey(),
            'mcp_connector_tool_id' => $tool->getKey(),
            'actor_type' => $user->getMorphClass(),
            'actor_id' => (string) $user->getKey(),
            'conversation_id' => (string) $conversation->getKey(),
            'resource_uri' => 'ui://reports/show.html',
            'tool_input' => [],
            'tool_result' => [],
            'model_context' => [
                'content' => [['type' => 'text', 'text' => 'The selected region is Europe.']],
                'structuredContent' => ['region' => 'EU'],
            ],
            'expires_at' => now()->addHour(),
        ]);
    }
}
