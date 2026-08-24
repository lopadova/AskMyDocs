<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\DefaultAgentRunHandler;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Contracts\AgentRunHandler;
use App\Models\AgentRun;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

final class DefaultAgentRunHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_handler_fails_closed_when_actor_and_linked_user_do_not_match(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner-context@example.com',
            'password' => Hash::make('secret-pass-123'),
            'locale' => 'it-IT',
        ]);
        $other = User::create([
            'name' => 'Other',
            'email' => 'other-context@example.com',
            'password' => Hash::make('secret-pass-123'),
            'locale' => 'it-IT',
        ]);
        $run = AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'acme',
            'project_key' => 'crm',
            'user_id' => $other->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => (string) $owner->id,
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_QUEUED,
            'input_json' => ['question' => 'Dati riservati'],
        ]);

        app(AgentRunHandler::class)->handle($run);

        $run->refresh();
        $this->assertSame(AgentRun::STATUS_FAILED, $run->status);
        $this->assertSame('unauthorized', $run->error_code);
        $this->assertSame(['run.failed'], $run->events()->pluck('type')->all());
        $this->assertSame(0, $run->toolExecutions()->count());
    }

    public function test_container_handler_runs_collection_synthesis_and_terminal_lifecycle(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            new AiResponse(
                content: '',
                provider: 'fake',
                model: 'fake-agent',
                toolCalls: [['name' => 'submit_agent_plan', 'arguments' => [
                    'decision' => 'answer',
                    'actions' => [],
                ]]],
            ),
            new AiResponse(
                content: '',
                provider: 'fake',
                model: 'fake-agent',
                toolCalls: [['name' => 'submit_agent_answer', 'arguments' => [
                    'answer' => 'Non risultano ordini disponibili per il cliente richiesto.',
                    'completeness' => 'insufficient',
                    'document_ids' => [],
                    'tool_execution_ids' => [],
                    'limitations' => ['Nessuna fonte ha restituito ordini.'],
                ]]],
            ),
        );
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $user = User::create([
            'name' => 'Agent user',
            'email' => 'handler-agent@example.com',
            'password' => Hash::make('secret-pass-123'),
            'locale' => 'it-IT',
        ]);

        $run = AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'acme',
            'project_key' => 'crm',
            'user_id' => $user->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_QUEUED,
            'input_json' => ['question' => 'Dammi gli ordini di Tizio'],
            'budget_json' => [],
            'counters_json' => [],
        ]);

        $handler = app(AgentRunHandler::class);
        $this->assertInstanceOf(DefaultAgentRunHandler::class, $handler);
        $handler->handle($run);

        $run->refresh();
        $this->assertSame(AgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('final', $run->result_json['phase']);
        $this->assertSame('it-IT', data_get($run->result_json, 'response.locale'));
        $this->assertSame('insufficient', data_get($run->result_json, 'response.completeness'));
        $this->assertSame('Non risultano ordini disponibili per il cliente richiesto.', data_get($run->result_json, 'response.answer'));
        $this->assertNotNull($run->completed_at);
        $this->assertSame('La risposta è pronta.', $run->events()->where('type', 'run.completed')->sole()->message);
        $this->assertFalse((bool) data_get(
            $run->events()->where('type', 'run.completed')->sole()->payload_json,
            'can_cancel',
            true,
        ));
    }
}
