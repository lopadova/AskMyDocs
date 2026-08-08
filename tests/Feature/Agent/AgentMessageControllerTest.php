<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentAnswer;
use App\Agent\AgentResultProjector;
use App\Agent\AgentRetrievalFiltersFactory;
use App\Http\Controllers\Api\AgentMessageController;
use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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
        $this->assertSame($run->run_id, data_get($conversation->messages()->sole()->metadata, 'agent_run_id'));
        Queue::assertPushed(ExecuteAgentRunJob::class, fn ($job): bool => $job->agentRunId === $run->id);

        $filters = app(AgentRetrievalFiltersFactory::class)->forRun(
            $run,
            \App\Agent\AgentExecutionContext::fromArray([
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
            'run_id' => \Illuminate\Support\Str::uuid()->toString(),
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
        );

        $projector = app(AgentResultProjector::class);
        $projector->project($run, $answer);
        $projector->project($run, $answer);

        $message = $conversation->messages()->sole();
        $this->assertSame($run->id, $message->agent_run_id);
        $this->assertSame('Ordine A-100 trovato.', $message->content);
        $this->assertSame(12, data_get($message->metadata, 'citations.0.document_id'));
        $this->assertSame('get_orders', data_get($message->metadata, 'tool_sources.0.tool'));
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
}
