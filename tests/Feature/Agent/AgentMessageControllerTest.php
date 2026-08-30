<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentAnswer;
use App\Agent\AgentExecutionContext;
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
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpAppInstance;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
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
