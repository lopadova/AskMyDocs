<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminCommandAudit;
use App\Models\AgentDiagnosticSpan;
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

        $validationGrounding = [
            'status' => 'grounded',
            'semantic_validation' => [[
                'stage' => 'initial_answer', 'attempted' => true, 'used' => true,
                'status' => 'accepted', 'answer' => true, 'probability_true' => 0.96,
                'threshold' => 0.9, 'model' => 'typesafe/jev-1.13',
                'state_layout' => 'shared_sources_v1', 'state_bytes' => 11798, 'pair_count' => 9, 'source_count' => 4,
                'checks' => [['claim_id' => 'claim_3', 'status' => 'accepted', 'source' => ['tool_execution_id' => 86],
                    'source_resolution' => ['method' => 'unique_evidence_hash', 'original_source' => ['evidence_hash' => 'original-hash']]]],
            ]],
        ];
        $assistantMessage->forceFill([
            'agent_run_id' => $run->id,
            'metadata' => ['source' => 'test', 'grounding' => $validationGrounding],
        ])->save();
        $run->forceFill(['result_json' => array_replace_recursive($run->result_json, [
            'response' => ['grounding' => $validationGrounding],
        ])])->save();

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
            ->assertJsonPath('schema_version', 2)
            ->assertJsonPath('kind', 'askmydocs.conversation_debug_transcript')
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('messages.0.content', 'Come funzionano le notifiche push?')
            ->assertJsonPath('messages.1.agent_run_id', $run->id)
            ->assertJsonPath('messages.1.validation.semantic.used', true)
            ->assertJsonPath('messages.1.validation.semantic.answer', true)
            ->assertJsonCount(2, 'messages')
            ->assertJsonCount(1, 'agent_runs')
            ->assertJsonPath('agent_runs.0.run_id', $run->run_id)
            ->assertJsonPath('agent_runs.0.validation.semantic.checks.0.probability_true', 0.96)
            ->assertJsonPath('agent_runs.0.validation.semantic.checks.0.state_layout', 'shared_sources_v1')
            ->assertJsonPath('agent_runs.0.validation.semantic.checks.0.state_bytes', 11798)
            ->assertJsonPath('agent_runs.0.validation.semantic.claims.0.source_resolution.method', 'unique_evidence_hash')
            ->assertJsonPath('messages.1.validation.semantic.claims.0.source.tool_execution_id', 86)
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

    public function test_admin_can_download_a_debug_transcript_for_the_active_tenant(): void
    {
        $admin = $this->user('admin');
        $conversation = $this->conversation($admin, 'Admin export');
        $this->message($conversation, 'assistant', 'Risposta finale della chat.');

        $this->actingAs($admin)
            ->getJson("/api/admin/conversations/{$conversation->id}/debug-transcript")
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('messages.0.content', 'Risposta finale della chat.');
    }

    public function test_non_administrator_cannot_download_a_debug_transcript(): void
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

    public function test_active_export_reports_an_open_kb_phase_and_real_activity_without_inventing_a_cause(): void
    {
        $this->freezeTime();
        $operator = $this->user('admin');
        $conversation = $this->conversation($operator, 'Pending KB');
        $run = $this->runFixture($conversation);
        $span = $this->spanFixture($run, 'knowledge.local_search', [
            'wait_for' => 'local_search_result', 'wait_reason' => 'search_started',
        ]);
        $span->forceFill(['started_at' => now()->subSeconds(45)])->save();
        $this->spanFixture($run, 'transport.event_stream');
        // Touching the parent run is not useful evidence of actual work.
        $run->forceFill(['updated_at' => now()])->save();

        $response = $this->actingAs($operator)
            ->getJson("/api/admin/conversations/{$conversation->id}/debug-transcript")
            ->assertOk()
            ->assertJsonPath('schema_version', 2)
            ->assertJsonPath('diagnostics.schema_version', 1)
            ->assertJsonPath('agent_runs.0.diagnostics.schema_version', 1)
            ->assertJsonPath('agent_runs.0.diagnostics.availability', 'recorded')
            ->assertJsonPath('agent_runs.0.diagnostics.activity_age_ms', 45000)
            ->assertJsonPath('agent_runs.0.diagnostics.last_activity.source', 'span.started')
            ->assertJsonPath('agent_runs.0.diagnostics.transport_last_activity.age_ms', 0)
            ->assertJsonPath('agent_runs.0.diagnostics.open_spans.0.age_ms', 45000)
            ->assertJsonPath('agent_runs.0.diagnostics.waiting.observed_phase', 'knowledge.local_search')
            ->assertJsonPath('agent_runs.0.diagnostics.waiting.reported_wait_for', 'local_search_result')
            ->assertJsonPath('agent_runs.0.diagnostics.waiting.cause', 'unknown')
            ->assertJsonPath('agent_runs.0.diagnostics.waiting.worker_liveness', 'not_probed')
            ->assertJsonPath('agent_runs.0.diagnostics.terminal_event.expected', false)
            ->assertJsonPath('agent_runs.0.diagnostics.warnings', []);
        $this->assertSame($run->run_id, $response->json('agent_runs.0.run_id'));
    }

    public function test_terminal_export_distinguishes_completed_failed_cancelled_and_missing_final_observations(): void
    {
        $operator = $this->user('admin');
        $conversation = $this->conversation($operator, 'Terminal diagnostics');
        foreach (['completed', 'failed', 'cancelled'] as $status) {
            $run = $this->runFixture($conversation, $status);
            $span = $this->spanFixture($run, 'knowledge.retrieve');
            $span->forceFill(['status' => $status, 'ended_at' => now(), 'duration_ms' => 12])->save();
            $run->events()->create(['sequence' => 1, 'type' => 'run.'.$status, 'phase' => 'run', 'locale' => 'it-IT']);
        }
        $incomplete = $this->runFixture($conversation, 'failed');
        $this->spanFixture($incomplete, 'knowledge.remote_search');
        // A forwarded branch event cannot stand in for the parent's final event.
        $incomplete->events()->create(['sequence' => 1, 'type' => 'run.completed', 'phase' => 'run', 'locale' => 'it-IT',
            'payload_json' => ['data' => ['research_run_id' => (string) Str::uuid()]]]);

        $response = $this->actingAs($operator)
            ->getJson("/api/admin/conversations/{$conversation->id}/debug-transcript")
            ->assertOk();
        foreach (['completed', 'failed', 'cancelled'] as $index => $status) {
            $response->assertJsonPath("agent_runs.{$index}.diagnostics.spans.0.status", $status)
                ->assertJsonPath("agent_runs.{$index}.diagnostics.terminal_event.recorded", true)
                ->assertJsonPath("agent_runs.{$index}.diagnostics.open_spans", [])
                ->assertJsonPath("agent_runs.{$index}.diagnostics.warnings", []);
        }
        $response->assertJsonPath('agent_runs.3.diagnostics.warnings', [
            'terminal_event_not_recorded', 'open_spans_in_terminal_run',
        ])->assertJsonPath('agent_runs.3.diagnostics.waiting.cause', 'unknown');
    }

    public function test_research_handoff_is_correlated_only_with_verified_tenant_scoped_children(): void
    {
        $operator = $this->user('admin');
        $conversation = $this->conversation($operator, 'Research handoff');
        $parent = $this->runFixture($conversation);
        $child = $this->runFixture($conversation, 'completed');
        $otherTenant = $this->runFixture($conversation, 'running', 'other-tenant');
        $child->forceFill(['input_json' => ['research_parent_id' => $parent->id, 'research_flow_id' => 0]])->save();
        $otherTenant->forceFill(['input_json' => ['research_parent_id' => $parent->id]])->save();
        $parent->forceFill(['result_json' => ['research_runs' => [$child->id, $otherTenant->id]]])->save();
        $this->spanFixture($parent, 'research.fanout', ['wait_for' => 'research_branches']);

        $this->actingAs($operator)
            ->getJson("/api/admin/conversations/{$conversation->id}/debug-transcript")
            ->assertOk()
            ->assertJsonCount(2, 'agent_runs')
            ->assertJsonCount(1, 'agent_runs.0.diagnostics.research_links.children')
            ->assertJsonPath('agent_runs.0.diagnostics.research_links.children.0.run_id', $child->run_id)
            ->assertJsonPath('agent_runs.1.diagnostics.research_links.parent.run_id', $parent->run_id)
            ->assertJsonPath('agent_runs.1.diagnostics.terminal_event.expected', false)
            ->assertJsonPath('agent_runs.1.diagnostics.terminal_event.research_events_forwarded_to_parent', true)
            ->assertJsonPath('agent_runs.1.diagnostics.warnings', []);
    }

    public function test_legacy_active_runs_have_explicitly_unknown_activity_and_no_stall_diagnosis(): void
    {
        $operator = $this->user('admin');
        $conversation = $this->conversation($operator, 'Legacy running');
        $this->runFixture($conversation, 'queued');

        $this->actingAs($operator)
            ->getJson("/api/admin/conversations/{$conversation->id}/debug-transcript")
            ->assertOk()
            ->assertJsonPath('agent_runs.0.diagnostics.availability', 'no_spans_recorded')
            ->assertJsonPath('agent_runs.0.diagnostics.last_activity_at', null)
            ->assertJsonPath('agent_runs.0.diagnostics.activity_age_ms', null)
            ->assertJsonPath('agent_runs.0.diagnostics.waiting.run_status', 'queued')
            ->assertJsonPath('agent_runs.0.diagnostics.waiting.observed_phase', null)
            ->assertJsonPath('agent_runs.0.diagnostics.waiting.cause', 'unknown');
    }

    public function test_export_sanitizes_nested_persisted_payloads_and_errors_while_preserving_technical_ids(): void
    {
        $operator = $this->user('admin');
        $conversation = $this->conversation($operator, 'Sanitization');
        $run = $this->runFixture($conversation);
        $run->forceFill(['run_id' => '30012345-b9a2-4c76-8a3e-8fbc2fce12d1',
            'input_json' => ['nested' => [['authorization' => 'secret-auth-value', 'password' => 'secret-password-value']]],
            'result_json' => ['error' => ['message' => 'Authorization: Bearer short-secret-token password=secret-password-value',
                'payload' => ['clientSecret' => 'secret-client-value']]],
        ])->save();
        $this->message($conversation, 'assistant', 'Contact private-person@example.test');
        $run->events()->create(['sequence' => 1, 'type' => 'run.failed', 'phase' => 'run', 'locale' => 'it-IT',
            'payload_json' => ['nested' => [['refresh_token' => 'secret-refresh-value']]]]);
        $this->spanFixture($run, 'knowledge.retrieve', ['nested' => ['api_key' => 'secret-api-value']]);

        $response = $this->actingAs($operator)
            ->getJson("/api/admin/conversations/{$conversation->id}/debug-transcript")
            ->assertOk()
            ->assertJsonPath('agent_runs.0.run_id', $run->run_id)
            ->assertJsonPath('agent_runs.0.input_json.nested.0.authorization', '[REDACTED]')
            ->assertJsonPath('agent_runs.0.result_json.error.payload.clientSecret', '[REDACTED]')
            ->assertJsonPath('agent_runs.0.events.0.payload_json.nested.0.refresh_token', '[REDACTED]');
        foreach (['secret-auth-value', 'secret-password-value', 'secret-client-value', 'secret-refresh-value',
            'secret-api-value', 'short-secret-token', 'private-person@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_new_diagnostic_fields_are_bounded_without_truncating_the_legacy_transcript(): void
    {
        $operator = $this->user('admin');
        $conversation = $this->conversation($operator, 'Bounds');
        $run = $this->runFixture($conversation);
        $content = str_repeat('Full retained transcript. ', 4000);
        $this->message($conversation, 'assistant', $content);
        for ($index = 0; $index < 257; $index++) {
            $this->spanFixture($run, 'knowledge.retrieve', ['wait_reason' => str_repeat('x', 4000)]);
        }

        $this->actingAs($operator)
            ->getJson("/api/admin/conversations/{$conversation->id}/debug-transcript")
            ->assertOk()
            ->assertJsonPath('messages.0.content', $content)
            ->assertJsonCount(256, 'agent_runs.0.diagnostics.spans')
            ->assertJsonPath('agent_runs.0.diagnostics.limits.spans_truncated', true)
            ->assertJsonPath('agent_runs.0.diagnostics.limits.span_capacity_reached', true)
            ->assertJsonPath('agent_runs.0.diagnostics.spans.0.metadata._truncated', true);
    }

    private function runFixture(Conversation $conversation, string $status = 'running', ?string $tenant = null): AgentRun
    {
        return AgentRun::create([
            'run_id' => (string) Str::uuid(), 'tenant_id' => $tenant ?? $conversation->tenant_id,
            'conversation_id' => $conversation->id, 'project_key' => $conversation->project_key,
            'channel' => 'chat', 'actor_type' => 'user', 'locale' => 'it-IT', 'timezone' => 'Europe/Rome',
            'status' => $status,
        ]);
    }

    private function spanFixture(AgentRun $run, string $phase, array $metadata = []): AgentDiagnosticSpan
    {
        return AgentDiagnosticSpan::create([
            'id' => (string) Str::uuid(), 'tenant_id' => $run->tenant_id, 'agent_run_id' => $run->id,
            'phase' => $phase, 'status' => 'started', 'metadata_json' => $metadata, 'started_at' => now(),
        ]);
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
