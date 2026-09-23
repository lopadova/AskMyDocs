<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminCommandAudit;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ConversationDebugTranscriptTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_downloads_the_complete_persisted_agent_debug_transcript(): void
    {
        $operator = $this->user('super-admin');
        $conversation = $this->conversation($operator, 'Push notifications');
        $userMessage = $this->message($conversation, 'user', 'Come funzionano le notifiche push?');
        $assistantMessage = $this->message($conversation, 'assistant', 'Le notifiche push arrivano sul telefono.');

        $run = AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'test-tenant',
            'project_key' => 'gescat',
            'user_id' => $operator->id,
            'conversation_id' => $conversation->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => (string) $operator->id,
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_COMPLETED,
            'input_json' => [
                'question' => 'Come funzionano le notifiche push?',
                'user_message_id' => $userMessage->id,
            ],
            'plan_json' => ['actions' => [['tool' => 'search_knowledge', 'purpose' => 'Find push documentation']]],
            'budget_json' => ['logical_calls' => 1, 'physical_calls' => 0],
            'counters_json' => ['kb_searches' => 1, 'tool_calls' => 1],
            'result_json' => [
                'evidence' => [
                    'documents' => [[
                        'document_id' => 73,
                        'title' => 'Manuale notifiche push',
                        'snippet' => 'Il pannello consente di comporre e programmare l’invio.',
                    ]],
                ],
                'response' => ['answer' => 'Le notifiche push arrivano sul telefono.'],
            ],
            'last_sequence' => 2,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);

        $assistantMessage->forceFill(['agent_run_id' => $run->id])->save();

        $run->events()->create([
            'sequence' => 1,
            'type' => 'knowledge.searching',
            'phase' => 'knowledge',
            'locale' => 'it-IT',
            'message_key' => 'knowledge.searching',
            'message_params' => ['query' => 'notifiche push'],
            'message' => 'Cerco nella knowledge base.',
            'payload_json' => [
                'data' => [
                    'query' => 'notifiche push',
                    'results' => [['document_id' => 73, 'title' => 'Manuale notifiche push']],
                ],
            ],
        ]);
        $run->events()->create([
            'sequence' => 2,
            'type' => 'answer.completed',
            'phase' => 'answer',
            'locale' => 'it-IT',
            'message' => 'Risposta pronta.',
        ]);
        $run->toolExecutions()->create([
            'logical_index' => 1,
            'tool_name' => 'search_knowledge',
            'tool_kind' => 'knowledge',
            'status' => 'completed',
            'arguments_json' => ['query' => 'notifiche push'],
            'result_meta_json' => [
                'result' => [
                    'documents' => [[
                        'document_id' => 73,
                        'title' => 'Manuale notifiche push',
                        'text' => 'Il pannello consente di comporre e programmare l’invio.',
                    ]],
                ],
                'complete' => true,
            ],
            'physical_request_count' => 0,
            'latency_ms' => 12,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        $run->plannerShadowReports()->create([
            'iteration' => 1,
            'tenant_id' => 'test-tenant',
            'project_key' => 'gescat',
            'mode' => 'shadow',
            'status' => 'agreement',
            'capability_hash' => str_repeat('a', 64),
            'capability_count' => 1,
            'capability_bytes' => 128,
            'candidate_tools_json' => ['search_knowledge'],
            'route_json' => ['intent' => 'knowledge_search'],
            'classic_plan_json' => ['actions' => [['tool' => 'search_knowledge']]],
            'capability_plan_json' => ['actions' => [['tool' => 'search_knowledge']]],
            'comparison_json' => ['decision_agreement' => true],
            'router_latency_ms' => 4,
            'planner_latency_ms' => 9,
            'prompt_tokens' => 21,
            'completion_tokens' => 8,
        ]);

        $otherConversation = $this->conversation($operator, 'Unrelated');
        $this->message($otherConversation, 'user', 'Do not include me.');
        AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'test-tenant',
            'conversation_id' => $otherConversation->id,
            'channel' => 'chat',
            'actor_type' => 'user',
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($operator)
            ->getJson("/api/admin/conversations/{$conversation->id}/debug-transcript")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('schema_version', 1)
            ->assertJsonPath('kind', 'askmydocs.conversation_debug_transcript')
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('messages.0.content', 'Come funzionano le notifiche push?')
            ->assertJsonPath('messages.1.agent_run_id', $run->id)
            ->assertJsonCount(2, 'messages')
            ->assertJsonCount(1, 'agent_runs')
            ->assertJsonPath('agent_runs.0.run_id', $run->run_id)
            ->assertJsonPath('agent_runs.0.input_json.question', 'Come funzionano le notifiche push?')
            ->assertJsonPath('agent_runs.0.result_json.evidence.documents.0.title', 'Manuale notifiche push')
            ->assertJsonPath('agent_runs.0.events.0.payload_json.data.query', 'notifiche push')
            ->assertJsonPath('agent_runs.0.tool_executions.0.arguments_json.query', 'notifiche push')
            ->assertJsonPath('agent_runs.0.tool_executions.0.result_meta_json.result.documents.0.text', 'Il pannello consente di comporre e programmare l’invio.')
            ->assertJsonPath('agent_runs.0.planner_shadow_reports.0.candidate_tools_json.0', 'search_knowledge');

        $this->assertStringContainsString(
            'attachment; filename="chat-debug-'.$conversation->id.'-',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertDatabaseHas('admin_command_audit', [
            'tenant_id' => 'test-tenant',
            'user_id' => $operator->id,
            'command' => 'chat:debug-transcript-download',
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_non_super_admin_cannot_download_a_debug_transcript(): void
    {
        $owner = $this->user('super-admin');
        $viewer = $this->user('viewer');
        $conversation = $this->conversation($owner, 'Private');

        $this->actingAs($viewer)
            ->getJson("/api/admin/conversations/{$conversation->id}/debug-transcript")
            ->assertForbidden();
    }

    public function test_a_conversation_from_another_tenant_is_not_exportable(): void
    {
        $operator = $this->user('super-admin');
        $otherTenantConversation = $this->conversation($operator, 'Other tenant', 'other-tenant');

        $this->actingAs($operator)
            ->getJson("/api/admin/conversations/{$otherTenantConversation->id}/debug-transcript")
            ->assertNotFound();
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-debug-'.uniqid().'@example.test',
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function conversation(User $user, string $title, string $tenantId = 'test-tenant'): Conversation
    {
        $context = app(TenantContext::class);
        $previous = $context->current();
        $context->set($tenantId);

        try {
            return Conversation::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'title' => $title,
                'project_key' => 'gescat',
            ]);
        } finally {
            $context->set($previous);
        }
    }

    private function message(Conversation $conversation, string $role, string $content): Message
    {
        return Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'role' => $role,
            'content' => $content,
            'metadata' => ['source' => 'test'],
        ]);
    }
}
