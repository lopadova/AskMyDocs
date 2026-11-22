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
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SessionRecoveryRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const INTRO = 'The return manual lists the steps in the Packing chapter.';
    private const DETAIL = 'Pack the garment in its original sealed bag.';

    public static function workersAndDepths(): array
    {
        return ['serial' => [false, 3], 'concurrent' => [true, 3], 'serial at depth limit' => [false, 1]];
    }

    #[DataProvider('workersAndDepths')]
    public function test_fresh_search_that_identifies_a_manual_can_recover_the_missing_section_once(bool $concurrent, int $depth): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['agent.planner.mode' => 'classic', 'reasoning.enabled' => true, 'reasoning.selective_validation' => true,
            'ai.providers.openrouter.key' => 'fixture', 'kb.investigation.enabled' => false, 'kb.investigation.full_source_max_chars' => 1,
            'agent.session_evidence.long_source_neighbor_radius' => 0]);
        app(TenantContext::class)->set('acme');
        $user = User::create(['name' => 'Reader', 'email' => 'reader@example.test', 'password' => bcrypt('fixture')]);
        $this->actingAs($user);
        ProjectMembership::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'returns', 'role' => 'member']);
        $chat = Conversation::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'project_key' => 'returns']);
        $question = 'How should I pack a garment for return?';
        $turn = $chat->messages()->create(['role' => 'user', 'content' => $question]);
        $doc = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'returns', 'source_type' => 'pdf',
            'title' => 'Return manual', 'source_path' => 'returns.pdf', 'document_hash' => hash('sha256', self::INTRO.self::DETAIL),
            'version_hash' => hash('sha256', self::INTRO.self::DETAIL), 'status' => 'active']);
        foreach (['Overview' => self::INTRO, 'Packing' => self::DETAIL] as $heading => $text) {
            $doc->chunks()->create(['tenant_id' => 'acme', 'project_key' => 'returns', 'chunk_order' => $heading === 'Overview' ? 0 : 1,
                'heading_path' => $heading, 'chunk_text' => $text, 'chunk_hash' => hash('sha256', $text)]);
        }
        $search = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $search->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect([['document' => $doc->toArray(),
            'chunk_id' => $doc->chunks()->orderBy('chunk_order')->value('id'), 'chunk_text' => self::INTRO]]), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $search);
        $this->simulateCoverage();
        $this->simulatePlannerAndWriter();

        $run = AgentRun::create(['tenant_id' => 'acme', 'project_key' => 'returns', 'run_id' => (string) Str::uuid(), 'user_id' => $user->id,
            'conversation_id' => $chat->id, 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'channel' => 'chat', 'locale' => 'en',
            'timezone' => 'UTC', 'status' => 'queued', 'input_json' => ['question' => $question, 'user_message_id' => $turn->id,
                'session_evidence' => true, 'concurrent_session' => $concurrent, 'depth' => $depth],
            'result_json' => ['question_understanding' => ['available' => true, 'language' => 'en', 'intent' => $question,
                'action' => 'research', 'requires_research' => true, 'focus' => ['topic' => 'Returns', 'identifiers' => [], 'aspect' => 'packing', 'fields' => ['*']],
                'subquestions' => [['topic' => 'Returns', 'identifiers' => [], 'aspect' => 'packing', 'fields' => ['*'], 'question' => $question]], 'deliverable' => ['kind' => 'procedure', 'quantity' => null, 'freshness' => 'source_context']]]]);
        $handler = app(AgentRunHandler::class);
        for ($delivery = 0; $delivery < 12 && ! $run->isTerminal(); $delivery++) {
            $handler->handle($run);
            $run->refresh();
        }
        $this->assertTrue($run->isTerminal());
        $handler->handle($run); // Redelivery of an acknowledged worker job.
        $executions = $run->toolExecutions()->where('status', 'completed')->orderBy('logical_index')->get();
        $this->assertSame($depth === 1 ? ['search_knowledge_base'] : ['search_knowledge_base', 'read_knowledge_context', 'read_knowledge_context'],
            $executions->pluck('tool_name')->all());
        $this->assertSame($depth === 1 ? [1] : [1, 2, 3], $executions->pluck('result_meta_json.dependency_depth')->all());
        $answer = $chat->messages()->where('agent_run_id', $run->id)->sole();
        if ($depth === 1) {
            $this->assertSame('partial', $run->result_json['response']['completeness']);
            $this->assertStringNotContainsString(self::DETAIL, $answer->content);
        } else {
            $this->assertSame('complete', $run->result_json['response']['completeness'],
                $run->result_json['response']['grounding']['reason'] ?? 'Incomplete after reading the requested chapter');
            $this->assertStringContainsString(self::DETAIL, $answer->content);
            $this->assertSame([0, 1], $executions->slice(1)->pluck('arguments_json.cursor')->values()->all());
            $this->assertSame($doc->id, $run->result_json['response']['citations'][0]['document_id']);
        }
    }

    private function simulateCoverage(): void
    {
        Http::fake(function ($request) {
            $answers = [];
            foreach ($request['questions'] as $key => $criterion) {
                if ($criterion['type'] === 'noul') {
                    $coverage = $key === 'covered' || str_starts_with($key, 'task_');
                    $answered = in_array(self::DETAIL, $request['state']['answer'] ?? [], true);
                    $answers[$key] = ['type' => 'noul', 'noul' => $coverage && ! $answered ? .01 : .99];
                } else {
                    $categories = array_keys($criterion['criteria']);
                    $choice = in_array('read', $categories, true) ? 'read' : $categories[0];
                    $answers[$key] = ['type' => 'choice', 'choice' => $choice, 'confidence' => 1,
                        'probabilities' => array_replace(array_fill_keys($categories, 0), [$choice => 1])];
                }
            }
            return Http::response(['id' => 'fixture-decision', 'model' => 'fixture', 'answers' => $answers]);
        });
    }

    private function simulatePlannerAndWriter(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->andReturnUsing(function ($system, $messages, $options) {
            $name = $options['tool_choice']['function']['name'];
            $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            if ($name === 'submit_agent_plan') {
                $history = $input['completed_actions'];
                $actions = [];
                if ($history === []) {
                    $actions[] = ['id' => 'find_manual', 'tool' => 'search_knowledge_base', 'purpose' => 'Find return manual',
                        'arguments' => ['query' => 'return manual'], 'depends_on' => []];
                } elseif (count($history) === 2) {
                    $map = $input['collected_evidence']['api_tools'][0]['result'];
                    $page = collect($map['section_map'])->firstWhere('heading', 'Packing');
                    $actions[] = ['id' => 'read_packing', 'tool' => 'read_knowledge_context', 'purpose' => 'Read packing procedure',
                        'arguments' => ['document_id' => $map['document_id'], 'cursor' => $page['ordinal'], 'source_version' => $map['source_version']],
                        'depends_on' => [$history[1]['id']]];
                }
                $arguments = ['decision' => $actions === [] ? 'answer' : 'tools', 'actions' => $actions];
            } else {
                $document = $input['evidence']['documents'][0];
                $passage = collect($document['evidence'])->first(fn ($row) => str_contains($row['content'], self::DETAIL)) ?? $document['evidence'][0];
                $fact = str_contains($passage['content'], self::DETAIL) ? self::DETAIL : self::INTRO;
                $arguments = ['claims' => [['text' => $fact, 'quote' => $fact, 'document_id' => $document['document_id'],
                    'evidence_hash' => $passage['evidence_hash'], 'subquestion_id' => 0]], 'completeness' => 'complete',
                    'limitations' => [], 'requires_selection' => false, 'render_table' => false];
            }
            return new AiResponse('', 'fake', 'fixture', toolCalls: [['name' => $name, 'arguments' => $arguments]]);
        });
        $this->app->instance(AiManager::class, $ai);
    }
}
