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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SessionAnswerReplayTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTIONS = ['A cosa serve WearFrame?', 'Può generare outfit?', 'Fammi qualche esempio.'];
    private const OVERVIEW = 'WearFrame prepara immagini per i cataloghi.';
    private const CAPABILITY = 'Il pannello Outfit combina modelli e capi.';
    private const EXAMPLES = [
        'In Outfit Oneshot puoi generare due immagini dello stesso capo cambiando soltanto la posa.',
        'Per confrontare due ambientazioni, mantieni il capo e la posa e cambia soltanto lo sfondo.',
    ];

    public static function verificationScenarios(): array
    {
        return [
            'recorded follow-up' => [false, false, false],
            'coverage provider unavailable' => [true, false, false],
            'interpreted examples in ordinary chat' => [false, false, true],
            'interpreted examples with concurrent workers' => [false, true, true],
        ];
    }

    #[DataProvider('verificationScenarios')]
    public function test_three_turn_session_recovers_examples_from_the_two_known_originals(bool $outage, bool $concurrent, bool $interpreted): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['agent.concurrent_session' => $concurrent, 'agent.waves.enabled' => true, 'reasoning.enabled' => true,
            'reasoning.selective_validation' => true, 'reasoning.selective_repair' => true, 'kb.investigation.enabled' => false,
            'ai.providers.openrouter.key' => 'fixture']);
        app(TenantContext::class)->set('acme');
        $user = User::create(['name' => 'QA', 'email' => 'session-replay@example.test', 'password' => bcrypt('fixture')]);
        $this->actingAs($user);
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'wf', 'user_id' => $user->id, 'role' => 'member']);
        $chat = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'wf', 'user_id' => $user->id]);
        $hits = [];
        foreach (['Presentazione' => self::OVERVIEW.' '.self::CAPABILITY, 'Manuale' => self::EXAMPLES[0], 'Guida' => self::EXAMPLES[1]] as $title => $body) {
            $hash = hash('sha256', $body);
            $doc = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'wf', 'source_type' => 'markdown',
                'title' => $title, 'source_path' => $title.'.md', 'document_hash' => $hash, 'version_hash' => $hash, 'status' => 'active']);
            $chunk = $doc->chunks()->create(['tenant_id' => 'acme', 'project_key' => 'wf', 'chunk_order' => 0, 'chunk_text' => $body, 'chunk_hash' => $hash]);
            $hits[] = ['chunk_id' => $chunk->id, 'chunk_text' => $body, 'document' => $doc->toArray()];
        }
        $search = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $search->shouldReceive('retrieve')->andReturn(new SearchResult(collect($hits), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $search);
        $this->fakeDecisions($outage);
        $this->fakeDrafts();

        $metrics = [];
        foreach (self::QUESTIONS as $index => $question) {
            $start = hrtime(true);
            $mode = $index === 0 ? 'new' : 'conversation';
            $focus = ['topic' => 'WearFrame', 'identifiers' => [], 'aspect' => $index === 0 ? 'utilizzo' : 'outfit', 'fields' => ['*']];
            $task = $focus + ['question' => $index === 2 ? 'Fornisci esempi di generazione di outfit con WearFrame.' : $question,
                'context_mode' => $mode, 'requires_research' => $index === 0, 'kb_queries' => $index === 0 ? ['WearFrame'] : []];
            $turn = $chat->messages()->create(['role' => 'user', 'content' => $question]);
            // Replay the interpretation persisted by the worker. In particular the last
            // turn was incorrectly labelled previous_reply and predates the deliverable contract.
            $run = AgentRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => 'acme',
                'project_key' => 'wf', 'user_id' => $user->id, 'conversation_id' => $chat->id,
                'actor_type' => 'user', 'actor_id' => (string) $user->id, 'channel' => 'chat', 'locale' => 'it', 'timezone' => 'UTC',
                'status' => 'queued', 'input_json' => ['question' => $question, 'user_message_id' => $turn->id, 'session_evidence' => true,
                    'concurrent_session' => $concurrent],
                'result_json' => ['question_understanding' => ['available' => true, 'language' => 'it', 'intent' => $task['question'],
                    'action' => $index === 0 ? 'research' : 'refine', 'references_previous_turn' => $index !== 0,
                    'transition' => $index === 0 ? 'new' : 'continue', 'context_mode' => $mode, 'focus' => $focus,
                    'subquestions' => [$task], 'requires_research' => $index === 0, 'requires_composition' => $index === 2,
                    'follow_up_target' => $index === 2 && ! $outage ? 'previous_reply' : 'sources',
                    ...$interpreted ? ['deliverable' => ['kind' => $index === 2 ? 'examples' : 'answer',
                        'quantity' => $index === 2 ? 2 : null, 'freshness' => 'source_context']] : []]]]);
            for ($delivery = 0; $delivery < 8 && ! $run->isTerminal(); $delivery++) {
                app(AgentRunHandler::class)->handle($run);
                $run->refresh();
            }
            $this->assertTrue($run->isTerminal());
            $response = $run->fresh()->result_json['response'] ?? [];
            $text = $response['answer'] ?? '';
            $expected = [[self::OVERVIEW], [self::CAPABILITY], self::EXAMPLES][$index];
            $useful = collect($expected)->every(fn ($fact) => str_contains($text, $fact));
            $metrics[] = ['satisfied' => $useful, 'global_searches' => $run->toolExecutions()->where('tool_name', 'search_knowledge_base')->where('status', 'completed')->count(),
                'repeated_generic_claims' => $index === 2 ? count(array_filter([self::OVERVIEW, self::CAPABILITY], fn ($fact) => str_contains($text, $fact))) : 0,
                'useful_answer_ms' => $useful ? round((hrtime(true) - $start) / 1e6, 1) : null];
        }
        if (getenv('ASKMYDOCS_QA_REPORT') === '1') {
            fwrite(STDERR, json_encode($metrics, JSON_THROW_ON_ERROR)."\n");
        }
        if ($outage) {
            $this->assertSame('partial', $response['completeness']);
            $this->assertSame('unknown', data_get($response, 'grounding.coverage_validation.tasks.0.status'));
            $this->assertSame('verification_unavailable', data_get($response, 'grounding.coverage_validation.next_action'));
            $this->assertNotEmpty(data_get($response, 'grounding.claims'));
            $this->assertSame(0, $run->toolExecutions()->count());
            $this->assertCount(3, $chat->messages()->where('role', 'assistant')->get());

            return;
        }
        $this->assertSame([true, true, true], array_column($metrics, 'satisfied'), json_encode($metrics));
        $this->assertSame(0, $metrics[2]['global_searches']);
        $this->assertSame(0, $metrics[2]['repeated_generic_claims']);
        $this->assertSame('complete', $response['completeness']);
        $this->assertCount(2, $response['citations']);
        $this->assertSame('satisfied', data_get($response, 'grounding.coverage_validation.tasks.0.status'));
        $this->assertSame(3, $chat->messages()->where('role', 'assistant')->count());
    }

    private function fakeDecisions(bool $outage): void
    {
        Http::fake(function ($request) use ($outage) {
            $state = $request['state'];
            if ($outage && isset($request['questions']['covered']) && ($state['question'] ?? null) === self::QUESTIONS[2]) {
                return Http::response([], 503);
            }
            $answers = [];
            foreach ($request['questions'] as $key => $criterion) {
                if ($criterion['type'] === 'noul') {
                    $examplesOmitted = ($key === 'covered' || str_starts_with($key, 'task_'))
                        && ($state['question'] ?? null) === self::QUESTIONS[2]
                        && array_diff(self::EXAMPLES, $state['answer'] ?? []) !== [];
                    $answers[$key] = ['type' => 'noul', 'noul' => $examplesOmitted ? .02 : .99];
                    continue;
                }
                $categories = array_keys($criterion['criteria']);
                $choice = $categories[0];
                if (isset($state['missing_task']) && str_starts_with($key, 'source_')) {
                    $source = $state['sources'][(int) substr($key, 7)];
                    $choice = in_array($source['text'] ?? '', self::EXAMPLES, true) ? 'compose' : 'search';
                }
                $answers[$key] = ['type' => 'choice', 'choice' => $choice, 'confidence' => 1,
                    'probabilities' => array_replace(array_fill_keys($categories, 0), [$choice => 1])];
            }
            return Http::response(['id' => 'decision', 'model' => 'fixture', 'answers' => $answers]);
        });
    }

    private function fakeDrafts(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->andReturnUsing(function ($system, $messages, $options) {
            $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            $tool = $options['tool_choice']['function']['name'];
            if ($tool === 'submit_agent_plan') {
                $actions = $input['completed_actions'] !== [] ? [] : [['id' => 'wearframe', 'tool' => 'search_knowledge_base',
                    'purpose' => 'Cerca la documentazione WearFrame', 'arguments' => ['query' => 'WearFrame'], 'depends_on' => []]];
                $arguments = ['decision' => $actions === [] ? 'answer' : 'tools', 'actions' => $actions];
            } else {
                $feedback = json_decode($input['turn_context'] ?? '{}', true);
                $facts = match ($input['question']) {
                    self::QUESTIONS[0] => [self::OVERVIEW],
                    self::QUESTIONS[1] => [self::CAPABILITY],
                    // Reordering older facts superficially changes the reply.
                    self::QUESTIONS[2] => isset($feedback['validation_feedback']) ? self::EXAMPLES : [self::CAPABILITY, self::OVERVIEW],
                };
                $claims = [];
                foreach ($facts as $fact) {
                    foreach ($input['evidence']['documents'] as $doc) {
                        foreach ($doc['evidence'] as $passage) {
                            if (str_contains($passage['content'], $fact)) {
                                $claims[] = ['text' => $fact, 'quote' => $fact, 'document_id' => $doc['document_id'],
                                    'evidence_hash' => $passage['evidence_hash'], 'subquestion_id' => 0];
                                continue 3;
                            }
                        }
                    }
                }
                $arguments = ['completeness' => 'complete', 'claims' => $claims, 'limitations' => [], 'requires_selection' => false, 'render_table' => false];
            }

            return new AiResponse('', 'fake', 'fixture', toolCalls: [['name' => $tool, 'arguments' => $arguments]]);
        });
        $this->app->instance(AiManager::class, $ai);
    }
}
