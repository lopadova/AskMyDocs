<?php

namespace Tests\Feature\Agent;

use App\Agent\AgentResearchCoordinator;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Evidence\ResearchEvidence;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\QuestionUnderstanding;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class IndependentResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_task_results_distinguish_partial_unverified_conflicting_and_failed_answers(): void
    {
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'project_key' => 'demo', 'channel' => 'chat',
            'actor_type' => 'user', 'actor_id' => '1', 'locale' => 'en', 'timezone' => 'UTC', 'status' => 'running',
            'result_json' => [
                'question_understanding' => ['subquestions' => array_fill(0, 5, ['question' => 'Independent task'])],
                'research_flows' => [['status' => 'completed'], ['status' => 'completed'], ['status' => 'completed'], ['status' => 'failed'], ['status' => 'completed']],
            ]]);
        $answer = new \App\Agent\AgentAnswer('Partial answer', 'en', 'partial', [], [], grounding: [
            'subquestions' => [['status' => 'answered'], ['status' => 'unverified'], ['status' => 'conflicting'], ['status' => 'unverified'], ['status' => 'answered']],
            'semantic_validation' => [['checks' => [['subquestion_id' => 0, 'status' => 'accepted', 'fallback' => 'structured_fields']]]],
        ]);
        app(\App\Agent\AgentResearchProgress::class)->finished($run, $answer);
        $tasks = $run->events()->sole()->payload_json['data']['tasks'];
        $this->assertSame(['partial', 'unverified', 'conflicting', 'failed', 'answered'], array_column($tasks, 'task_status'));
        $this->assertSame([0, 1, 2, 3, 4], array_column($tasks, 'id'));
    }

    public function test_kb_flow_tags_survive_citation_building_and_shared_chunk_deduplication(): void
    {
        $chunks = collect();
        foreach ([0 => 10, 1 => 20, 2 => 10] as $flow => $id) {
            $text = 'ITEM-'.$id.' is active.';
            $chunks->push(['document' => ['id' => $id, 'title' => 'Item '.$id], 'chunk_id' => $id,
                'chunk_text' => $text, 'chunk_hash' => hash('sha256', $text), 'research_flow_id' => $flow]);
        }
        $evidence = app(AgentEvidenceFactory::class)->fromSearchResult(new \App\Services\Kb\Retrieval\SearchResult($chunks, collect(), collect()));
        foreach ([0 => 10, 1 => 20, 2 => 10] as $flow => $id) {
            $scoped = ResearchEvidence::forFlow($evidence->jsonSerialize(), $flow);
            $this->assertCount(1, $scoped['documents']);
            $this->assertSame($id, $scoped['documents'][0]['document_id']);
        }
    }

    public function test_explicit_recap_assigns_historical_tools_by_entity_not_previous_task_index(): void
    {
        $tools = [
            ['execution_id' => 1, 'research_flow_ids' => [9], 'result' => ['records' => [['id' => 'ORDER-A']]]],
            ['execution_id' => 2, 'research_flow_ids' => [0], 'result' => ['records' => [['id' => 'CLIENT-B']]]],
            ['execution_id' => 3, 'result' => ['query' => 'ORDER-A', 'records' => [['id' => 'UNRELATED']]]],
        ];
        $scoped = ResearchEvidence::recapTools($tools, [['identifiers' => ['ORDER-A']], ['identifiers' => ['CLIENT-B']]]);
        $this->assertCount(2, $scoped);
        $this->assertSame([0], $scoped[0]['research_flow_ids']);
        $this->assertSame([1], $scoped[1]['research_flow_ids']);
    }

    public function test_parent_saves_one_combined_answer_and_reuses_cached_drafts_and_jev(): void
    {
        config(['reasoning.enabled' => true, 'reasoning.parallel_research' => true, 'reasoning.selective_validation' => true,
            'ai.providers.openrouter.key' => 'test', 'agent.planner.mode' => 'classic']);
        app(TenantContext::class)->set('default');
        $user = User::create(['name' => 'Test', 'email' => 'combined@example.test', 'password' => bcrypt('test')]);
        \App\Models\ProjectMembership::create(['user_id' => $user->id, 'project_key' => 'demo', 'role' => 'member']);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_key' => 'demo']);
        $turn = $conversation->messages()->create(['role' => 'user', 'content' => 'ITEM-0 and ITEM-1']);
        $initial = app(AgentEvidenceFactory::class)->empty();
        $subs = [];
        $documents = [];
        for ($i = 0; $i < 2; $i++) {
            $subs[] = ['topic' => 'item', 'identifiers' => ['ITEM-'.$i], 'aspect' => 'status', 'fields' => ['status'],
                'question' => 'Status of ITEM-'.$i, 'kb_queries' => ['ITEM-'.$i]];
            $text = 'ITEM-'.$i.' is active.';
            $doc = \App\Models\KnowledgeDocument::create(['project_key' => 'demo', 'source_type' => 'text', 'title' => 'Item '.$i,
                'source_path' => 'fixture/item-'.$i, 'mime_type' => 'text/plain', 'status' => 'active',
                'document_hash' => hash('sha256', $text), 'version_hash' => Str::random(32)]);
            $chunk = \App\Models\KnowledgeChunk::create(['project_key' => 'demo', 'knowledge_document_id' => $doc->id,
                'chunk_order' => 0, 'chunk_text' => $text, 'chunk_hash' => hash('sha256', $text)]);
            $documents[$i] = ['id' => $doc->id, 'text' => $text, 'hash' => $chunk->chunk_hash];
            $initial->import(ResearchEvidence::tagged(['documents' => [['document_id' => $doc->id,
                'evidence' => [['chunk_id' => $chunk->id, 'content' => $text, 'evidence_hash' => $chunk->chunk_hash]]]]], $i));
        }
        $u = new QuestionUnderstanding('en', 'Two independent items', ['ITEM-0', 'ITEM-1'], [], false, true,
            array_intersect_key($subs[0], array_flip(['topic', 'identifiers', 'aspect', 'fields'])), $subs);
        $parent = AgentRun::create(['run_id' => (string) Str::uuid(), 'project_key' => 'demo', 'user_id' => $user->id,
            'conversation_id' => $conversation->id, 'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id,
            'locale' => 'en', 'timezone' => 'UTC', 'status' => 'queued',
            'input_json' => ['question' => $turn->content, 'user_message_id' => $turn->id],
            'result_json' => ['question_understanding' => $u->toArray(), 'retrieval_completed' => true, 'evidence' => $initial->jsonSerialize()]]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chatWithProvider');
        $ai->shouldReceive('chatWithHistory')->times(4)->andReturnUsing(function ($system, $messages, $options) use ($documents) {
            if (data_get($options, 'tool_choice.function.name') === 'submit_agent_plan') {
                return new AiResponse('', 'fake', 'test', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => ['decision' => 'answer', 'actions' => []]]]);
            }
            $this->assertStringContainsString('The server renders section_title as the task heading', $system);
            $this->assertStringContainsString('Write only the section body', $system);
            $this->assertStringContainsString('short paragraphs within this section', $system);
            $input = json_decode($messages[0]['content'], true);
            $i = (int) substr($input['question'], -1);
            return new AiResponse('', 'fake', 'test', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => [
                'claims' => [['text' => $documents[$i]['text'], 'document_id' => $documents[$i]['id'], 'evidence_hash' => $documents[$i]['hash']]],
                'section_title' => 'Item ITEM-'.$i,
                'completeness' => 'complete', 'render_table' => false, 'requires_selection' => false,
            ]]]);
        });
        $this->app->instance(AiManager::class, $ai);
        \Illuminate\Support\Facades\Http::fake(function ($request) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                $probabilities = array_fill_keys(array_keys($question['criteria']), .01);
                $probabilities['supported_relevant'] = .96;
                $answers[$key] = ['type' => 'choice', 'choice' => 'supported_relevant', 'confidence' => .96, 'probabilities' => $probabilities];
            }
            return \Illuminate\Support\Facades\Http::response(['id' => 'decision', 'model' => 'jev-test', 'answers' => $answers]);
        });
        app(\App\Contracts\AgentRunHandler::class)->handle($parent);
        $parent->refresh();
        $this->assertSame('completed', $parent->status, json_encode($parent->result_json));
        $answer = $conversation->messages()->where('role', 'assistant')->sole();
        $this->assertStringContainsString('ITEM-0 is active.', $answer->content);
        $this->assertStringContainsString('ITEM-1 is active.', $answer->content);
        $this->assertSame("## Item ITEM-0\n\nITEM-0 is active.\n\n## Item ITEM-1\n\nITEM-1 is active.", $answer->content);
        $this->assertSame('Item ITEM-0', $parent->result_json['research_drafts'][0]['payload']['section_title']);
        $this->assertCount(2, $parent->result_json['research_drafts']);
        $this->assertCount(2, $conversation->fresh()->reasoning_state['communicated']);
        $progress = $parent->events()->where('type', 'research.finished')->sole()->payload_json['data']['tasks'];
        $this->assertSame(['answered', 'answered'], array_column($progress, 'task_status'));
        app(\App\Contracts\AgentRunHandler::class)->handle($parent);
        $outcome = new \App\Agent\AgentLoopOutcome('answer', $initial, []);
        app(\App\Agent\AgentAnswerSynthesizer::class)->synthesize($turn->content,
            \App\Agent\AgentExecutionContext::fromArray($parent->getAttributes()), $outcome,
            understanding: $parent->result_json['question_understanding'], durableRunId: $parent->run_id);
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function test_four_tasks_have_separate_plans_budgets_and_retry_checkpoints_without_new_interpretations(): void
    {
        config(['reasoning.enabled' => true, 'reasoning.parallel_research' => true, 'agent.planner.mode' => 'classic']);
        app(TenantContext::class)->set('default');
        $user = User::create(['name' => 'Test', 'email' => 'research@example.test', 'password' => bcrypt('test')]);
        $conversation = Conversation::create(['user_id' => $user->id, 'project_key' => 'demo']);
        $subs = [];
        $initial = app(AgentEvidenceFactory::class)->empty();
        for ($i = 0; $i < 4; $i++) {
            $subs[] = ['topic' => 'item', 'identifiers' => ['ITEM-'.$i], 'aspect' => 'details', 'fields' => ['*'],
                'question' => 'Details of ITEM-'.$i, 'kb_queries' => ['ITEM-'.$i]];
            $initial->import(ResearchEvidence::tagged(['documents' => [['document_id' => $i + 1,
                'evidence' => [['chunk_id' => $i + 1, 'content' => 'ITEM-'.$i.' is active.', 'evidence_hash' => 'hash-'.$i]]]]], $i));
        }
        $u = new QuestionUnderstanding('en', 'Four independent tasks', ['ITEM-0'], [], false, true,
            array_intersect_key($subs[0], array_flip(['topic', 'identifiers', 'aspect', 'fields'])), $subs);
        $parent = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'default', 'project_key' => 'demo',
            'user_id' => $user->id, 'conversation_id' => $conversation->id, 'channel' => 'chat', 'actor_type' => 'user',
            'actor_id' => (string) $user->id, 'locale' => 'en', 'timezone' => 'UTC', 'status' => 'running',
            'input_json' => ['question' => 'Four requests'], 'result_json' => ['question_understanding' => $u->toArray()]]);
        $reader = Mockery::mock(KbSourceReader::class);
        $reader->shouldReceive('readCandidate')->andReturnUsing(fn ($candidate) => ['excerpt' => 'ITEM-'.($candidate['document']['id'] - 1).' is active.']);
        $this->app->instance(KbSourceReader::class, $reader);
        $seen = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chatWithProvider');
        $ai->shouldReceive('chatWithHistory')->times(4)->andReturnUsing(function ($system, $messages) use (&$seen) {
            $input = json_decode($messages[0]['content'], true);
            $seen[] = $input['question'];
            $id = substr($input['question'], -1);
            for ($other = 0; $other < 4; $other++) {
                if ((string) $other !== $id) {
                    $this->assertStringNotContainsString('ITEM-'.$other, json_encode($input['evidence_summary']));
                }
            }
            if ($id === '2') {
                throw new \RuntimeException('One branch unavailable');
            }
            return new AiResponse('', 'fake', 'test', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => ['decision' => 'answer', 'actions' => []]]]);
        });
        $this->app->instance(AiManager::class, $ai);
        $coordinator = app(AgentResearchCoordinator::class);
        $outcome = $coordinator->collect($parent, $initial, $u);
        $this->assertCount(4, array_unique($seen));
        $this->assertSame('partial', $outcome->decision);
        $flows = $parent->fresh()->result_json['research_flows'];
        $this->assertSame(['completed', 'completed', 'failed', 'completed'], array_column($flows, 'status'));
        $children = AgentRun::whereIn('id', $parent->result_json['research_runs'])->get();
        $this->assertSame(25, $children->sum(fn ($child) => $child->budget_json['research_allocation']['logical_hard']));
        $this->assertCount(4, $outcome->evidence->documents());
        $coordinator->collect($parent, $initial, $u);
        $this->assertSame(5, AgentRun::count());
        $this->assertSame(0, $conversation->messages()->count());
        $this->assertGreaterThan(0, $parent->events()->where('type', 'plan.created')->count());
        $this->assertSame(1, $parent->events()->where('type', 'research.planned')->count());
        $progress = $parent->events()->where('type', 'research.task')->orderBy('sequence')->get()->pluck('payload_json.data');
        $this->assertSame([0, 1, 2, 3], $progress->where('task_status', 'researching')->pluck('research_flow_id')->all());
        $this->assertSame([0, 1, 3], $progress->where('task_status', 'collected')->pluck('research_flow_id')->all());
        $this->assertSame([2], $progress->where('task_status', 'failed')->pluck('research_flow_id')->all());
    }
}
