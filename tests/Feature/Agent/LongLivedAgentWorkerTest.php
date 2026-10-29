<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentChatTurnStarter;
use App\Agent\AgentExecutionContextFactory;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Ai\AgentChatModelScope;
use App\Models\AgentDiagnosticSpan;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LongLivedAgentWorkerTest extends TestCase
{
    use RefreshDatabase;

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    public static function firstStatus(): array
    {
        return ['completed' => [false], 'partial' => [true]];
    }

    #[DataProvider('firstStatus')]
    public function test_one_database_worker_processes_three_consecutive_turns(bool $partial): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false,
            'agent.queue' => 'long-lived-test', 'agent.planner.mode' => 'classic',
            'kb.investigation.enabled' => false, 'reasoning.enabled' => false]);
        Http::preventStrayRequests();
        app(TenantContext::class)->set('worker-tenant');
        app(PackageTenantContext::class)->set('worker-tenant');
        $user = User::create(['name' => 'Worker', 'email' => 'long-worker@example.test',
            'password' => 'password', 'locale' => 'it']);
        $conversation = Conversation::create(['tenant_id' => 'worker-tenant', 'user_id' => $user->id,
            'project_key' => 'orders', 'title' => 'Three turns']);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->andReturnUsing(function (string $system) {
            if (str_contains($system, 'submit_agent_plan')) {
                return new AiResponse('', 'fake', 'fake', toolCalls: [['name' => 'submit_agent_plan',
                    'arguments' => ['decision' => 'answer', 'actions' => []]]]);
            }
            return new AiResponse('', 'fake', 'fake', toolCalls: [['name' => 'submit_agent_answer',
                'arguments' => ['completeness' => 'insufficient', 'claims' => [],
                    'limitations' => ['Nessuna fonte.'], 'requires_selection' => false, 'render_table' => false]]]);
        });
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $starter = app(AgentChatTurnStarter::class);
        $start = function (int $number) use ($starter, $conversation, $user): AgentRun {
            app(TenantContext::class)->set('worker-tenant');
            $context = app(AgentExecutionContextFactory::class)->forUser($user, 'orders');
            return $starter->start($context, $conversation, $user, "Domanda $number",
                ['question' => "Domanda $number"])->run;
        };
        $first = $start(1);
        if ($partial) {
            $first->forceFill(['budget_json' => ['research_allocation' => ['iterations' => 0]]])->save();
        }
        $runs = [$first->id];
        $pids = [];
        $clean = [];
        app(TenantContext::class)->reset();
        app(PackageTenantContext::class)->reset();
        $tenantBaseline = app(TenantContext::class)->current();
        $packageBaseline = app(PackageTenantContext::class)->current();
        App::setLocale('en');
        date_default_timezone_set('UTC');
        Event::listen(JobProcessed::class, function (JobProcessed $event) use (&$runs, &$pids, &$clean, $start, $tenantBaseline, $packageBaseline): void {
            $pids[] = getmypid();
            $run = AgentRun::withoutGlobalScopes()->findOrFail(end($runs));
            $this->assertTrue($run->isTerminal());
            $clean[] = [app(TenantContext::class)->current(), app(PackageTenantContext::class)->current(),
                App::currentLocale(), date_default_timezone_get(), Auth::user()];
            $this->assertSame([$tenantBaseline, $packageBaseline, 'en', 'UTC', null], end($clean));
            $this->assertNull(app(AgentChatModelScope::class)->context());
            if (count($runs) < 3) {
                $runs[] = $start(count($runs) + 1)->id;
                app(TenantContext::class)->reset();
            }
        });
        $status = app('queue.worker')->daemon('database', 'long-lived-test',
            new WorkerOptions(memory: 1024, sleep: 0, maxTries: 1, force: true, stopWhenEmpty: true, maxJobs: 3));
        $this->assertSame(0, $status);
        $this->assertCount(3, $runs);
        $this->assertCount(3, $pids);
        $this->assertSame([getmypid()], array_values(array_unique($pids)));
        $this->assertSame($partial ? AgentRun::STATUS_PARTIAL : AgentRun::STATUS_COMPLETED,
            AgentRun::withoutGlobalScopes()->findOrFail($runs[0])->status);
        foreach ($runs as $index => $id) {
            $run = AgentRun::withoutGlobalScopes()->findOrFail($id);
            $this->assertSame($index === 0 && $partial ? AgentRun::STATUS_PARTIAL : AgentRun::STATUS_COMPLETED, $run->status);
            $this->assertSame(1, $run->events()->where('type', 'run.started')->count());
            $this->assertSame(1, $conversation->messages()->where('agent_run_id', $id)->where('role', 'assistant')->count());
            foreach (['queue.wait', 'worker.execute'] as $phase) {
                $this->assertSame(1, AgentDiagnosticSpan::where('agent_run_id', $id)->where('phase', $phase)
                    ->where('status', 'completed')->whereNotNull('ended_at')->count());
            }
            $lock = Cache::lock('agent-run:'.$run->tenant_id.':'.$run->run_id, 10);
            $this->assertTrue($lock->get());
            $lock->release();
        }
        $this->assertSame(0, DB::table('jobs')->where('queue', 'long-lived-test')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        Http::assertNothingSent();
    }

    public function test_worker_preserves_reasoning_across_three_turns_with_a_real_search_wave(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false,
            'agent.queue' => 'reasoning-worker-test', 'agent.planner.mode' => 'classic',
            'agent.waves.enabled' => true, 'agent.waves.combined_initial_planning' => true,
            'kb.investigation.enabled' => true, 'reasoning.enabled' => true,
            'reasoning.selective_validation' => false]);
        Http::preventStrayRequests();
        app(TenantContext::class)->set('worker-tenant');
        app(PackageTenantContext::class)->set('worker-tenant');
        $user = User::create(['name' => 'Worker', 'email' => 'reasoning-worker@example.test',
            'password' => 'password', 'locale' => 'it']);
        \App\Models\ProjectMembership::create(['tenant_id' => 'worker-tenant', 'user_id' => $user->id,
            'project_key' => 'orders', 'role' => 'member']);
        $conversation = Conversation::create(['tenant_id' => 'worker-tenant', 'user_id' => $user->id,
            'project_key' => 'orders', 'title' => 'Reasoning']);
        \App\Models\KbRetrievalProfile::create(['tenant_id' => 'worker-tenant', 'project_key' => 'orders',
            'company_context' => 'Ordini']);
        $text = 'Il reso U532638 è stato ricevuto.';
        $document = \App\Models\KnowledgeDocument::create(['tenant_id' => 'worker-tenant', 'project_key' => 'orders',
            'source_type' => 'markdown', 'title' => 'Reso', 'source_path' => 'reso.md', 'status' => 'active',
            'document_hash' => hash('sha256', $text)]);
        $chunk = \App\Models\KnowledgeChunk::create(['tenant_id' => 'worker-tenant', 'project_key' => 'orders',
            'knowledge_document_id' => $document->id, 'chunk_order' => 0, 'chunk_text' => $text,
            'chunk_hash' => hash('sha256', $text)]);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->andReturn(new SearchResult(collect([[
            'chunk_id' => $chunk->id, 'chunk_text' => $text, 'chunk_hash' => $chunk->chunk_hash,
            'project_key' => 'orders', 'document' => $document->toArray(),
        ]]), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $interpretations = 0;
        $reasoningContexts = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->andReturnUsing(function ($provider, $system, $messages) use (&$interpretations, &$reasoningContexts) {
            $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
            $reasoningContexts[] = $input['reasoning_context'] ?? null;
            $index = $interpretations++;
            $followup = $index > 0;
            $question = $followup ? 'Riepiloga il reso U532638' : 'Cerca il reso U532638';
            $focus = ['topic' => 'reso', 'identifiers' => ['U532638'], 'aspect' => 'status', 'fields' => []];
            $result = ['language' => 'it', 'intent' => $question, 'action' => $followup ? 'recap' : 'research',
                'kb_queries' => $followup ? [] : ['U532638'], 'mentions' => $followup ? [] : [['text' => 'U532638', 'type' => 'identifier']],
                'references_previous_turn' => $followup, 'focus' => $focus,
                'subquestions' => [[...$focus, 'question' => $question, 'kb_queries' => $followup ? [] : ['U532638'],
                    'context_mode' => $followup ? 'conversation' : 'new', 'requires_research' => ! $followup]],
                'context_mode' => $followup ? 'conversation' : 'new', 'requires_research' => ! $followup,
                'resolved_references' => $followup ? ['U532638'] : [], 'transition' => $followup ? 'recap' : 'new',
                'needs_clarification' => false, 'clarification' => ''];
            if (! $followup) {
                $result['initial_plan'] = ['decision' => 'tools', 'actions' => [[
                    'id' => 'kb', 'tool' => 'search_knowledge_base', 'arguments_json' => '{"query":"U532638"}',
                    'depends_on' => [], 'purpose' => 'Cerca il reso',
                ]]];
            }
            return new AiResponse(json_encode($result, JSON_THROW_ON_ERROR), 'fake', 'interpreter');
        });
        $ai->shouldReceive('chatWithHistory')->andReturnUsing(function (string $system) {
            if (str_contains($system, 'submit_agent_plan')) {
                return new AiResponse('', 'fake', 'planner', toolCalls: [['name' => 'submit_agent_plan',
                    'arguments' => ['decision' => 'answer', 'actions' => []]]]);
            }
            return new AiResponse('', 'fake', 'answer', toolCalls: [['name' => 'submit_agent_answer',
                'arguments' => ['completeness' => 'insufficient', 'claims' => [],
                    'limitations' => ['Nessuna fonte.'], 'requires_selection' => false, 'render_table' => false]]]);
        });
        $this->app->instance(AiManager::class, $ai);
        $starter = app(AgentChatTurnStarter::class);
        $start = function (int $number) use ($starter, $conversation, $user): AgentRun {
            app(TenantContext::class)->set('worker-tenant');
            $question = $number === 1 ? 'Cerca il reso U532638' : 'Riepiloga il reso U532638';
            return $starter->start(app(AgentExecutionContextFactory::class)->forUser($user, 'orders'),
                $conversation, $user, $question, ['question' => $question])->run;
        };
        $runs = [$start(1)->id];
        $pids = [];
        app(TenantContext::class)->reset();
        app(PackageTenantContext::class)->reset();
        Event::listen(JobProcessed::class, function () use (&$runs, &$pids, $start): void {
            $pids[] = getmypid();
            $this->assertTrue(AgentRun::withoutGlobalScopes()->findOrFail(end($runs))->isTerminal());
            $this->assertNull(app(AgentChatModelScope::class)->context());
            if (count($runs) < 3) {
                $runs[] = $start(count($runs) + 1)->id;
                app(TenantContext::class)->reset();
            }
        });
        app('queue.worker')->daemon('database', 'reasoning-worker-test',
            new WorkerOptions(memory: 1024, sleep: 0, maxTries: 1, force: true, stopWhenEmpty: true, maxJobs: 3));
        $this->assertCount(3, $runs);
        $this->assertSame([getmypid()], array_values(array_unique($pids)));
        $first = AgentRun::withoutGlobalScopes()->findOrFail($runs[0]);
        $this->assertNotEmpty(data_get($first->result_json, 'waves'));
        $this->assertNotEmpty(data_get($first->result_json, 'evidence.documents'));
        $this->assertNotEmpty($reasoningContexts[1]);
        $this->assertSame('recap', data_get(AgentRun::withoutGlobalScopes()->findOrFail($runs[1])->result_json, 'question_understanding.action'));
        $this->assertNotEmpty($conversation->fresh()->reasoning_state);
        foreach ($runs as $id) {
            $run = AgentRun::withoutGlobalScopes()->findOrFail($id);
            $this->assertContains($run->status, [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL]);
            $this->assertSame(1, $run->events()->where('type', 'run.started')->count());
            $this->assertSame(1, $conversation->messages()->where('agent_run_id', $id)->where('role', 'assistant')->count());
            foreach (['queue.wait', 'worker.execute'] as $phase) {
                $this->assertSame(1, AgentDiagnosticSpan::where('agent_run_id', $id)->where('phase', $phase)
                    ->where('status', 'completed')->whereNotNull('ended_at')->count());
            }
        }
        $this->assertSame(0, DB::table('jobs')->where('queue', 'reasoning-worker-test')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        Http::assertNothingSent();
    }
}
