<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentAnswer;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\VerifiedAnswerCheckpoint;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Contracts\AgentRunHandler;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\ProjectMembership;
use App\Support\TenantContext;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class AgentFinalizationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_projection_failure_rolls_back_completion_before_failure_handling(): void
    {
        config()->set('kb.investigation.enabled', false);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            new AiResponse(content: '', provider: 'fake', model: 'fake', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => ['decision' => 'answer', 'actions' => []]]]),
            new AiResponse(content: '', provider: 'fake', model: 'fake', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => ['completeness' => 'insufficient', 'claims' => [], 'limitations' => [], 'requires_selection' => false, 'render_table' => false]]]),
        );
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $user = User::create(['name' => 'Test', 'email' => 'atomicity@example.com', 'password' => bcrypt('secret-pass-123'), 'locale' => 'it-IT']);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'user_id' => $user->id, 'title' => 'Test', 'project_key' => 'crm']);
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'crm',
            'user_id' => $user->id, 'conversation_id' => $conversation->id, 'channel' => 'chat', 'actor_type' => 'user',
            'actor_id' => (string) $user->id, 'locale' => 'it-IT', 'timezone' => 'Europe/Rome', 'status' => 'queued',
            'input_json' => ['question' => 'Test'], 'budget_json' => [], 'counters_json' => []]);
        $failed = false;
        DB::listen(function ($query) use (&$failed): void {
            if (! $failed && str_contains(strtolower($query->sql), 'insert into "messages"')) {
                $failed = true;
                throw new \RuntimeException('projection failed');
            }
        });

        app(AgentRunHandler::class)->handle($run);

        $this->assertTrue($failed);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertFalse($run->events()->whereIn('type', ['run.completed', 'run.partial'])->exists());
        $this->assertDatabaseMissing('messages', ['agent_run_id' => $run->id]);
    }
    public function test_projection_failure_recovers_verified_checkpoint_atomically(): void
    {
        app(TenantContext::class)->set('acme');
        app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->set('acme');
        config()->set('kb.investigation.enabled', false);
        $user = User::create(['name' => 'Tester', 'email' => 'checkpoint-atomic@example.test', 'password' => bcrypt('test'), 'is_active' => true]);
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $user->id, 'role' => 'member']);
        $this->actingAs($user);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $user->id]);
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'orders',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id,
            'conversation_id' => $conversation->id, 'locale' => 'it', 'timezone' => 'UTC', 'status' => AgentRun::STATUS_RUNNING,
            'input_json' => ['question' => 'Quali sono le informazioni verificate?']]);
        $text = 'Informazione verificata numero 1.';
        $hash = hash('sha256', $text);
        $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'source_type' => 'markdown',
            'title' => 'Documento 1', 'source_path' => (string) Str::uuid(), 'status' => 'active', 'document_hash' => $hash]);
        $chunk = KnowledgeChunk::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'knowledge_document_id' => $document->id,
            'chunk_order' => 0, 'chunk_text' => $text, 'chunk_hash' => $hash]);
        $claim = ['text' => $text, 'quote' => $text, 'evidence_hash' => $hash, 'document_id' => $document->id, 'tool_execution_id' => null];
        $answer = new AgentAnswer($text, 'it', 'complete', [['document_id' => $document->id, 'title' => $document->title]], [],
            grounding: ['status' => 'grounded', 'claims' => [$claim]]);
        app(VerifiedAnswerCheckpoint::class)->save($run, $answer, ['documents' => [['document_id' => $document->id,
            'title' => $document->title, 'origin' => 'primary', 'evidence' => [['chunk_id' => $chunk->id, 'content' => $text, 'evidence_hash' => $hash]]]],
            'api_tools' => [], 'warnings' => []]);
        $checkpoint = $run->fresh()->result_json['verified_answer_checkpoint'];
        $ai = Mockery::mock(AiManager::class);
        $calls = 0;
        $ai->shouldReceive('chatWithHistory')->twice()->andReturnUsing(function () use (&$calls, $run, $checkpoint): AiResponse {
            $calls++;
            if ($calls === 1) {
                return new AiResponse(content: '', provider: 'fake', model: 'fake', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => ['decision' => 'answer', 'actions' => []]]]);
            }
            $current = $run->fresh();
            $current->update(['result_json' => [...($current->result_json ?? []), 'verified_answer_checkpoint' => $checkpoint]]);
            return new AiResponse(content: '', provider: 'fake', model: 'fake', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => ['completeness' => 'insufficient', 'claims' => [], 'limitations' => [], 'requires_selection' => false, 'render_table' => false]]]);
        });
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $failures = 0;
        DB::listen(function ($query) use (&$failures): void {
            if ($failures === 0 && str_contains(strtolower($query->sql), 'insert into "messages"')) {
                $failures++;
                throw new \RuntimeException('projection failed once');
            }
        });

        app(AgentRunHandler::class)->handle($run);

        $this->assertSame(1, $failures);
        $this->assertSame(AgentRun::STATUS_PARTIAL, $run->fresh()->status);
        $this->assertSame('agent_run_failed', data_get($run->fresh()->result_json, 'response.grounding.recovery.reason'));
        $this->assertStringContainsString($text, Message::query()->where('agent_run_id', $run->id)->sole()->content);
        $this->assertSame(1, $run->events()->where('type', 'run.partial')->count());
        $this->assertSame(0, $run->events()->whereIn('type', ['run.failed', 'run.completed'])->count());
    }

    public function test_answered_research_tasks_are_published_before_terminal_event(): void
    {
        $user = User::create(['name' => 'Tester', 'email' => 'event-order@example.test', 'password' => bcrypt('test')]);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $user->id]);
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'orders',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id,
            'conversation_id' => $conversation->id, 'locale' => 'it', 'timezone' => 'UTC', 'status' => AgentRun::STATUS_RUNNING,
            'result_json' => ['question_understanding' => ['subquestions' => [['question' => 'One'], ['question' => 'Two']]]]]);
        $answer = new AgentAnswer('Verified', 'it', 'complete', [], [], grounding: [
            'subquestions' => [['status' => 'answered'], ['status' => 'answered']],
            'semantic_validation' => [['checks' => [['subquestion_id' => 0, 'status' => 'accepted'], ['subquestion_id' => 1, 'status' => 'accepted']]]],
        ]);
        $outcome = new AgentLoopOutcome('answer', app(AgentEvidenceFactory::class)->empty(), []);
        $method = new \ReflectionMethod(\App\Agent\DefaultAgentRunHandler::class, 'finalize');
        $this->assertTrue($method->invoke(app(AgentRunHandler::class), $run, AgentRun::STATUS_COMPLETED, $outcome, $answer));

        $events = $run->events()->whereIn('type', ['research.finished', 'run.completed'])->orderBy('sequence')->get();
        $this->assertSame(['research.finished', 'run.completed'], $events->pluck('type')->all());
        $this->assertSame(['answered', 'answered'], array_column($events[0]->payload_json['data']['tasks'], 'task_status'));
        $this->assertCount(1, Message::query()->where('agent_run_id', $run->id)->get());
    }

}
