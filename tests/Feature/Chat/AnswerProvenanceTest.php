<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Agent\AgentExecutionContext;
use App\Agent\Tools\AgentToolRegistry;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Contracts\AgentRunHandler;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageStreamController;
use App\Models\AgentRun;
use App\Models\AgentToolExecution;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\User;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Services\Chat\AnswerProvenance;
use App\Services\Chat\Reasoning\ConversationReasoning;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Widget\WidgetOrchestratorService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnection;
use Padosoft\AskMyDocsConnectorMcp\Models\McpConnectionTool;
use Padosoft\AskMyDocsConnectorMcp\Models\McpServerDefinition;
use Tests\TestCase;

final class AnswerProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $chat;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('acme');
        app(ConnectorTenantContext::class)->set('acme');
        config(['reasoning.enabled' => true, 'kb.investigation.enabled' => true, 'chat-log.enabled' => false,
            'connector-mcp.enabled' => true, 'connector-mcp.runtime_mode' => 'active']);
        Cache::flush();
        Http::preventStrayRequests();
        $this->user = User::create(['name' => 'Source tester', 'email' => 'sources@example.test', 'password' => bcrypt('test'), 'locale' => 'it']);
        \App\Models\ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'demo', 'user_id' => $this->user->id, 'role' => 'member']);
        $this->chat = Conversation::create(['tenant_id' => 'acme', 'user_id' => $this->user->id, 'project_key' => 'demo']);
    }

    private function interpretation(string $locale = 'it', array $ids = []): array
    {
        $focus = ['topic' => 'answer sources', 'identifiers' => $ids, 'aspect' => 'source_provenance', 'fields' => []];
        return ['language' => $locale, 'intent' => 'Fonti della risposta precedente', 'action' => 'provenance', 'kb_queries' => ['Fonti della risposta precedente'],
            'mentions' => array_map(fn ($id) => ['text' => $id, 'type' => 'identifier'], $ids), 'references_previous_turn' => true,
            'transition' => 'provenance', 'focus' => $focus, 'subquestions' => [
                $focus + ['question' => 'Quali fonti hai usato?', 'kb_queries' => ['Fonti della risposta precedente']],
            ], 'resolved_references' => [], 'needs_clarification' => false, 'clarification' => ''];
    }

    private function turn(array $ids = []): Message
    {
        return $this->chat->messages()->create(['role' => 'user', 'content' => 'Dove hai trovato questi dati?',
            'metadata' => ['reasoning' => ['understanding' => $this->interpretation(ids: $ids) + ['available' => true]]]]);
    }

    private function document(string $title = 'Conferma spedizione', string $type = 'imap', string $tenant = 'acme', string $project = 'demo'): array
    {
        $document = KnowledgeDocument::create(['tenant_id' => $tenant, 'project_key' => $project, 'source_type' => $type,
            'title' => $title, 'source_path' => 'mail/'.Str::uuid(), 'mime_type' => 'text/plain', 'status' => 'active',
            'document_hash' => hash('sha256', $title), 'version_hash' => Str::uuid()->toString()]);
        $chunk = KnowledgeChunk::create(['tenant_id' => $tenant, 'project_key' => $project, 'knowledge_document_id' => $document->id,
            'chunk_order' => 0, 'chunk_hash' => hash('sha256', $title), 'chunk_text' => 'Ordine PO-5582, spedizione RL-TRACK-9355.']);
        return ['document_id' => $document->id, 'title' => $title, 'origin' => 'primary', 'chunks' => [['chunk_id' => $chunk->id]],
            'claims' => [['text' => 'Ordine PO-5582, spedizione RL-TRACK-9355.']]];
    }

    private function agentRun(array $input = []): AgentRun
    {
        return AgentRun::create(['run_id' => Str::uuid()->toString(), 'tenant_id' => 'acme', 'project_key' => 'demo',
            'user_id' => $this->user->id, 'conversation_id' => $this->chat->id, 'channel' => 'chat', 'actor_type' => 'user',
            'actor_id' => (string) $this->user->id, 'locale' => 'it', 'timezone' => 'Europe/Rome', 'status' => 'queued',
            'input_json' => $input, 'budget_json' => [], 'counters_json' => []]);
    }

    /** A real connector registration and durable execution, without contacting the server. */
    private function tool(string $kind = 'mcp'): array
    {
        $name = $kind === 'mcp' ? 'search_shipments_12345678' : 'lookup_complaints';
        if ($kind === 'mcp') {
            $server = McpServerDefinition::create(['tenant_id' => 'acme', 'name' => 'Logistics', 'catalog_scope' => 'tenant',
                'transport' => 'auto', 'auth_mode' => 'none', 'endpoint' => 'https://example.test/mcp', 'status' => 'active']);
            $connection = McpConnection::create(['tenant_id' => 'acme', 'mcp_connector_server_id' => $server->id,
                'mode' => 'shared', 'label' => 'Logistics', 'project_key' => 'demo', 'status' => 'active']);
            $registration = McpConnectionTool::create(['tenant_id' => 'acme', 'mcp_connector_connection_id' => $connection->id,
                'remote_name' => 'search_shipments', 'local_name' => $name, 'description' => 'Shipment details',
                'input_schema_json' => ['type' => 'object', 'properties' => []],
                'annotations_json' => ['readOnlyHint' => true, 'idempotentHint' => true],
                'risk' => 'read', 'policy' => 'enabled', 'enabled' => true, 'confirmation_required' => false]);
        } else {
            $connector = ApiConnector::create(['tenant_id' => 'acme', 'project_key' => 'demo', 'name' => 'Complaints', 'is_active' => true]);
            $registration = ApiRoute::create(['tenant_id' => 'acme', 'api_connector_id' => $connector->id, 'project_key' => 'demo',
                'name' => 'Complaints', 'slug' => $name, 'description' => 'Complaints', 'http_method' => 'GET', 'url' => 'https://example.test/complaints',
                'mode' => 'tool', 'status' => 'active', 'endpoint_type' => 'list',
                'tool_definition' => ['name' => $name, 'description' => 'Complaints', 'input_schema' => ['type' => 'object', 'properties' => []]]]);
        }
        $run = $this->agentRun();
        $context = AgentExecutionContext::fromArray($run->only(['run_id', 'tenant_id', 'project_key', 'channel', 'actor_type', 'actor_id', 'locale', 'timezone']));
        $definition = app(AgentToolRegistry::class)->forContext($context, $this->user)[$name];
        $execution = $run->toolExecutions()->create(['logical_index' => 1, 'tool_name' => $name, 'tool_kind' => $kind,
            'status' => 'completed', 'completed_at' => now()->subMinute(), 'arguments_json' => ['query' => 'PO-5582']]);
        $result = ['records' => [['id' => 'RL-TRACK-9355', 'orderId' => 'PO-5582']]];
        $hash = hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE));
        $run->update(['status' => 'completed', 'result_json' => ['evidence' => ['api_tools' => [[
            'execution_id' => $execution->id, 'tool' => $name, 'kind' => $kind, 'result' => $result, 'evidence_hash' => $hash,
            'executor_reference' => $definition->executorReference,
        ]]]]]);
        return [['execution_id' => $execution->id, 'tool' => $name, 'evidence_hash' => $hash], $registration];
    }

    private function explain(Message $turn, ?RetrievalFilters $filters = null, ?array $liveSources = null): \App\Agent\AgentAnswer
    {
        return app(AnswerProvenance::class)->explain($this->chat, $turn, $this->user, 'it', $filters, $liveSources);
    }

    private function expectOneInterpretation(string $locale = 'it'): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse(json_encode($this->interpretation($locale)), 'fake', 'interpreter'));
        $ai->shouldNotReceive('chatWithHistory');
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldNotReceive('retrieve');
        $this->app->instance(ChatRetrievalService::class, $retrieval);
    }

    public function test_lists_only_recorded_document_email_mcp_and_api_sources_without_refreshing(): void
    {
        $email = $this->document();
        $document = $this->document('Manuale operativo', 'text');
        $rejected = $this->document('SCARTATA');
        $rejected['origin'] = 'rejected';
        [$mcp] = $this->tool();
        [$api] = $this->tool('api');
        $previous = $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Risposta verificata.',
            'metadata' => ['citations' => [$email, $document, $rejected], 'tool_sources' => [$mcp, $api],
                'tool_calls' => [['name' => 'UNUSED_TOOL']], 'grounding' => ['claims' => []]]]);
        $answer = $this->explain($this->turn());
        $this->assertSame('complete', $answer->completeness);
        $this->assertSame(['email', 'document', 'mcp', 'api'], array_column($answer->grounding['provenance']['sources'], 'kind'));
        $this->assertSame($previous->id, $answer->grounding['provenance']['answer_id']);
        $this->assertSame(0, $answer->grounding['provenance']['new_tool_calls']);
        $this->assertFalse($answer->grounding['semantic_validation']['used']);
        $this->assertCount(2, $answer->citations);
        $this->assertCount(2, $answer->toolSources);
        $this->assertStringContainsString('Non ho effettuato una nuova ricerca', $answer->answer);
        $this->assertStringNotContainsString('SCARTATA', $answer->answer);
        $this->assertStringNotContainsString('UNUSED_TOOL', $answer->answer);
        $this->assertStringNotContainsString('Ordine PO-5582', $answer->answer);
    }

    public function test_revoked_foreign_tenant_project_and_filtered_documents_are_not_disclosed(): void
    {
        config(['kb.mentions.mode' => 'filter']);
        $valid = $this->document('Allowed');
        $revoked = $this->document('REVOKED');
        KnowledgeDocument::whereKey($revoked['document_id'])->update(['status' => 'archived']);
        $foreign = $this->document('FOREIGN', tenant: 'other');
        $project = $this->document('OTHERPROJECT', project: 'private');
        $filtered = $this->document('FILTERED');
        $restricted = $this->document('RESTRICTED');
        KnowledgeDocument::whereKey($restricted['document_id'])->update(['source_acl_enforced_at' => now()]);
        $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Previous',
            'metadata' => ['citations' => [$valid, $revoked, $foreign, $project, $filtered, $restricted]]]);
        $answer = $this->explain($this->turn(), new RetrievalFilters(docIds: [$valid['document_id'], $restricted['document_id']]));
        $this->assertCount(1, $answer->citations);
        $this->assertSame(5, $answer->grounding['provenance']['unavailable_references']);
        $this->assertSame('partial', $answer->completeness);
        foreach (['REVOKED', 'FOREIGN', 'OTHERPROJECT', 'FILTERED', 'RESTRICTED'] as $hidden) {
            $this->assertStringNotContainsString($hidden, json_encode($answer));
        }
    }

    public function test_tools_require_current_authorization_and_original_execution_hash(): void
    {
        [$mcp, $registration] = $this->tool();
        $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Previous', 'metadata' => ['tool_sources' => [$mcp]]]);
        $turn = $this->turn();
        $this->assertCount(1, $this->explain($turn)->toolSources);
        $this->assertSame([], $this->explain($turn, liveSources: ['mcp' => []])->toolSources);
        $registration->update(['enabled' => false]);
        $this->assertSame([], $this->explain($turn)->toolSources);
        $registration->update(['enabled' => true]);
        $execution = AgentToolExecution::findOrFail($mcp['execution_id']);
        $run = $execution->run;
        $result = $run->result_json;
        $result['evidence']['api_tools'][0]['result']['records'][0]['id'] = 'TAMPERED';
        $run->update(['result_json' => $result]);
        $answer = $this->explain($turn);
        $this->assertSame([], $answer->toolSources);
        $this->assertStringNotContainsString('TAMPERED', json_encode($answer));
    }

    public function test_same_named_tool_or_execution_from_another_conversation_is_not_provenance(): void
    {
        [$mcp] = $this->tool();
        $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Previous', 'metadata' => ['tool_sources' => [$mcp]]]);
        $turn = $this->turn();
        $execution = AgentToolExecution::findOrFail($mcp['execution_id']);
        $run = $execution->run;
        $result = $run->result_json;
        $result['evidence']['api_tools'][0]['executor_reference'] = 'a different connector';
        $run->update(['result_json' => $result]);
        $this->assertSame([], $this->explain($turn)->toolSources);
        $other = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'demo', 'user_id' => $this->user->id]);
        $run->update(['conversation_id' => $other->id]);
        $this->assertSame([], $this->explain($turn)->toolSources);
    }

    public function test_latest_uncited_answer_does_not_borrow_older_or_future_sources(): void
    {
        $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Older', 'metadata' => ['citations' => [$this->document('OLDER')]]]);
        $previous = $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Uncited']);
        $turn = $this->turn();
        $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Future', 'metadata' => ['citations' => [$this->document('FUTURE')]]]);
        $answer = $this->explain($turn);
        $this->assertSame($previous->id, $answer->grounding['provenance']['answer_id']);
        $this->assertSame([], $answer->citations);
        $this->assertStringContainsString('Non ho riferimenti', $answer->answer);
        $this->assertStringNotContainsString('OLDER', json_encode($answer));
        $this->assertStringNotContainsString('FUTURE', json_encode($answer));
    }

    public function test_empty_history_and_targeted_attribution_are_explicit(): void
    {
        $this->assertStringContainsString('Non c’è ancora', $this->explain($this->turn())->answer);
        $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Previous', 'metadata' => ['citations' => [$this->document()]]]);
        $this->assertCount(1, $this->explain($this->turn(['PO-5582']))->citations);
        $this->assertSame([], $this->explain($this->turn(['PO-558']))->citations);
    }

    public function test_foreign_turn_is_rejected(): void
    {
        $other = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'demo', 'user_id' => $this->user->id]);
        $turn = $other->messages()->create(['role' => 'user', 'content' => 'Sources?']);
        $this->expectException(\DomainException::class);
        $this->explain($turn);
    }

    public function test_mixed_questions_and_failed_interpretations_do_not_use_the_shortcut(): void
    {
        $data = $this->interpretation() + ['available' => true];
        $data['subquestions'][] = ['topic' => 'shipment', 'identifiers' => ['TRACK-55'], 'aspect' => 'status', 'fields' => ['status']];
        $this->assertFalse(\App\Services\Chat\QuestionUnderstanding::fromArray($data)->asksForProvenance());
        $data = $this->interpretation() + ['available' => false];
        $this->assertFalse(\App\Services\Chat\QuestionUnderstanding::fromArray($data)->asksForProvenance());
    }

    public function test_document_titles_are_escaped_and_old_or_changed_content_is_not_repeated(): void
    {
        $citation = $this->document('Report [click](https://example.test) **title**');
        KnowledgeDocument::whereKey($citation['document_id'])->update(['source_path' => 'https://user:secret@example.test/doc?token=secret']);
        KnowledgeChunk::whereKey($citation['chunks'][0]['chunk_id'])->update(['chunk_text' => 'Changed business fact SECRET']);
        $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Previous', 'metadata' => ['citations' => [$citation]]]);
        $answer = $this->explain($this->turn());
        $this->assertStringContainsString('\\[click\\]', $answer->answer);
        $this->assertStringNotContainsString('SECRET', json_encode($answer));
        $this->assertStringNotContainsString('token=secret', json_encode($answer));
        $this->assertNull($answer->citations[0]['source_path']);
    }

    public function test_preprocessor_is_cached_and_attribution_does_not_replace_business_focus(): void
    {
        $this->expectOneInterpretation();
        $state = app(ConversationReasoning::class)->state($this->chat);
        $state['objective'] = 'Seguire la spedizione';
        $state['focus'] = ['topic' => 'shipment', 'identifiers' => [], 'aspect' => 'status', 'fields' => ['status']];
        $this->chat->forceFill(['reasoning_state' => $state])->save();
        $turn = $this->chat->messages()->create(['role' => 'user', 'content' => 'Dove hai trovato questi dati?']);
        $memory = app(ConversationReasoning::class);
        $this->assertTrue($memory->prepare($this->chat, $turn, $turn->content)->asksForProvenance());
        $this->assertTrue($memory->prepare($this->chat->fresh(), $turn->fresh(), $turn->content)->asksForProvenance());
        $this->assertSame($state['focus'], $this->chat->fresh()->reasoning_state['focus']);
        $this->assertSame($state['objective'], $this->chat->fresh()->reasoning_state['objective']);
    }

    public function test_agent_uses_one_interpretation_no_retrieval_planning_tools_or_synthesis_and_retry_is_idempotent(): void
    {
        $this->expectOneInterpretation();
        $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Previous', 'metadata' => ['citations' => [$this->document()]]]);
        $turn = $this->chat->messages()->create(['role' => 'user', 'content' => 'Dove hai trovato questi dati?']);
        $run = $this->agentRun(['question' => $turn->content, 'user_message_id' => $turn->id]);
        app(AgentRunHandler::class)->handle($run);
        $run->refresh();
        $this->assertSame('completed', $run->status, json_encode($run->error_code));
        $this->assertSame('answer_provenance', $run->result_json['stop_reason']);
        $this->assertSame(0, $run->toolExecutions()->count());
        $saved = $this->chat->messages()->where('agent_run_id', $run->id)->firstOrFail();
        $this->assertSame(['kb_searches' => 0, 'tool_calls' => 0], $saved->metadata['search_stats']);
        $this->assertFalse($saved->metadata['grounding']['semantic_validation']['used']);
        app(AgentRunHandler::class)->handle($run->fresh());
        $this->assertSame(1, $this->chat->messages()->where('agent_run_id', $run->id)->count());
    }

    public function test_sync_endpoint_can_explain_sources_without_a_retrieval_profile(): void
    {
        $this->expectOneInterpretation();
        Route::post('/provenance/{conversation}', [MessageController::class, 'store'])->middleware(SubstituteBindings::class);
        $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Previous', 'metadata' => ['citations' => [$this->document()]]]);
        $response = $this->actingAs($this->user)->postJson('/provenance/'.$this->chat->id, ['content' => 'Dove hai trovato questi dati?']);
        $response->assertOk()->assertJsonPath('metadata.grounding.status', 'provenance')->assertJsonPath('metadata.search_stats.kb_searches', 0);
        $this->assertStringContainsString('Conferma spedizione', $response->json('content'));
    }

    public function test_stream_endpoint_saves_the_same_attribution_and_emits_a_complete_text_envelope(): void
    {
        $this->expectOneInterpretation('en');
        Route::post('/provenance-stream/{conversation}', [MessageStreamController::class, 'store'])->middleware(SubstituteBindings::class);
        $this->chat->messages()->create(['role' => 'assistant', 'content' => 'Previous', 'metadata' => ['citations' => [$this->document()]]]);
        $response = $this->actingAs($this->user)->postJson('/provenance-stream/'.$this->chat->id, ['content' => 'Where did you find these data?']);
        $response->assertOk();
        $stream = $response->streamedContent();
        $this->assertStringContainsString('Sources for the previous answer', $stream);
        foreach (['start', 'source-url', 'text-start', 'text-delta', 'text-end', 'finish'] as $event) {
            $this->assertStringContainsString('"type":"'.$event.'"', $stream);
        }
        $saved = $this->chat->messages()->where('role', 'assistant')->latest('id')->first();
        $this->assertTrue($saved->metadata['streamed']);
        $this->assertSame('provenance', $saved->metadata['grounding']['status']);
    }

    public function test_widget_uses_shared_attribution_instead_of_a_new_search(): void
    {
        $this->expectOneInterpretation();
        $key = WidgetKey::create(['tenant_id' => 'acme', 'project_key' => 'demo', 'public_key' => 'pk_provenance',
            'allowed_origins' => ['https://example.test'], 'rate_limit' => 100, 'skill' => 'askmydocs-assistant@1', 'is_active' => true]);
        $session = WidgetSession::create(['tenant_id' => 'acme', 'widget_key_id' => $key->id, 'project_key' => 'demo',
            'public_session_id' => Str::uuid()->toString(), 'status' => 'active', 'skill' => 'askmydocs-assistant@1', 'locale' => 'it']);
        $session->steps()->create(['step_index' => 0, 'kind' => 'bot_message', 'args_json' => ['content' => 'Previous', 'citations' => [$this->document()]]]);
        $response = app(WidgetOrchestratorService::class)->step($session, [], 'Dove hai trovato questi dati?', null);
        $this->assertStringContainsString('Conferma spedizione', $response['answer']);
        $saved = $session->steps()->where('kind', 'bot_message')->latest('id')->first();
        $this->assertSame('provenance', $saved->args_json['grounding']['status']);
    }
}
