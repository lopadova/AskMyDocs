<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\DefaultAgentRunHandler;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Contracts\AgentRunHandler;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpAppInstance;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
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
        $requests = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')
            ->twice()
            ->withArgs(function (string $system, array $history) use (&$requests): bool {
                $requests[] = json_decode(
                    (string) data_get($history, '0.content'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                return true;
            })
            ->andReturn(
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

        config()->set('connector-mcp.enabled', true);
        config()->set('connector-mcp.runtime_mode', 'active');
        config()->set('connector-mcp.apps.advanced_enabled', true);
        app(TenantContext::class)->set('acme');
        app(ConnectorTenantContext::class)->set('acme');

        $user = User::create([
            'name' => 'Agent user',
            'email' => 'handler-agent@example.com',
            'password' => Hash::make('secret-pass-123'),
            'locale' => 'it-IT',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'title' => 'Agent app context',
            'project_key' => 'crm',
        ]);
        $server = McpServerDefinition::query()->create([
            'tenant_id' => 'acme',
            'name' => 'Agent handler MCP App',
            'transport' => 'auto',
            'auth_mode' => 'none',
            'endpoint' => 'https://handler-app.example.test/mcp',
            'status' => McpServerDefinition::STATUS_ACTIVE,
        ]);
        $connection = McpConnection::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_server_id' => $server->getKey(),
            'mode' => 'shared',
            'label' => 'Agent handler MCP App',
            'status' => McpConnection::STATUS_ACTIVE,
        ]);
        $tool = McpConnectionTool::query()->create([
            'tenant_id' => 'acme',
            'mcp_connector_connection_id' => $connection->getKey(),
            'remote_name' => 'reports.show',
            'local_name' => 'handler_mcp_app_reports_show_12345678',
            'input_schema_json' => ['type' => 'object'],
            'risk' => 'read',
            'policy' => 'disabled',
            'enabled' => false,
            'confirmation_required' => false,
        ]);
        $instance = McpAppInstance::query()->create([
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
            'status' => AgentRun::STATUS_QUEUED,
            'input_json' => [
                'question' => 'Dammi gli ordini di Tizio',
                'mcp_app_id' => $instance->public_id,
            ],
            'budget_json' => [],
            'counters_json' => [],
        ]);

        $handler = app(AgentRunHandler::class);
        $this->assertInstanceOf(DefaultAgentRunHandler::class, $handler);
        $handler->handle($run);

        $run->refresh();
        $this->assertCount(2, $requests);
        $this->assertSame(AgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('final', $run->result_json['phase']);
        $this->assertSame('it-IT', data_get($run->result_json, 'response.locale'));
        $this->assertSame('insufficient', data_get($run->result_json, 'response.completeness'));
        $this->assertSame('Non risultano ordini disponibili per il cliente richiesto.', data_get($run->result_json, 'response.answer'));
        $this->assertStringContainsString('The selected region is Europe.', (string) $requests[0]['mcp_app_context']);
        $this->assertStringContainsString('"region":"EU"', (string) $requests[1]['mcp_app_context']);
        $this->assertNotNull($run->completed_at);
        $this->assertSame('La risposta è pronta.', $run->events()->where('type', 'run.completed')->sole()->message);
        $this->assertFalse((bool) data_get(
            $run->events()->where('type', 'run.completed')->sole()->payload_json,
            'can_cancel',
            true,
        ));
    }
}
