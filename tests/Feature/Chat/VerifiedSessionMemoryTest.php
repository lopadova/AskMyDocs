<?php

namespace Tests\Feature\Chat;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoop;
use App\Agent\Evidence\AgentEvidenceAccess;
use App\Agent\Tools\AgentToolRegistry;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Contracts\AgentRunHandler;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageStreamController;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\SessionMemoryFact;
use App\Models\SessionMemoryObservation;
use App\Models\User;
use App\Services\Chat\QuestionAction;
use App\Services\Chat\QuestionUnderstanding;
use App\Services\Chat\Reasoning\ConversationReasoning;
use App\Services\Chat\Reasoning\SessionFactMemory;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class VerifiedSessionMemoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conversation $chat;
    private SessionFactMemory $memory;
    private const TOOL = 'search_knowledge_base';

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        config(['reasoning.enabled' => true, 'reasoning.session_memory.enabled' => true,
            'reasoning.session_memory.ttl_seconds' => 300, 'reasoning.selective_validation' => false,
            'kb.investigation.enabled' => true, 'kb.session_recap.enabled' => false]);
        Http::preventStrayRequests();
        $this->freezeTime();
        $this->user = User::create(['name' => 'Tester', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]);
        $this->chat = Conversation::create(['user_id' => $this->user->id, 'project_key' => 'demo']);
        \App\Models\ProjectMembership::create(['user_id' => $this->user->id, 'project_key' => 'demo', 'role' => 'member', 'scope_allowlist' => []]);
        $this->memory = app(SessionFactMemory::class);
    }

    private function understanding(array $ids = [], array $fields = ['status'], bool $refresh = false, bool $complete = true, ?QuestionAction $action = null): QuestionUnderstanding
    {
        $focus = ['topic' => 'order', 'identifiers' => ['ORD-123'], 'aspect' => 'details', 'fields' => $fields];
        $sub = [...$focus, 'question' => 'Informazioni su ORD-123', 'kb_queries' => ['ORD-123'], 'context_mode' => 'new', 'requires_research' => true];
        return new QuestionUnderstanding('it', 'Informazioni su ORD-123', ['ORD-123'], [['text' => 'ORD-123', 'type' => 'identifier']],
            false, focus: $focus, subquestions: [$sub], transition: $action === QuestionAction::Recap ? 'recap' : 'new',
            action: $action ?? QuestionAction::Research, requiresResearch: true, memoryDecisions: [[
                'task_index' => 0, 'fact_ids' => $ids, 'refresh_requested' => $refresh, 'complete' => $complete,
                'collection' => false, 'scope_matches' => false, 'missing_question' => 'Tracking di ORD-123',
            ]]);
    }

    private function runFixture(?Conversation $chat = null, ?\App\Models\Message $turn = null): AgentRun
    {
        $chat ??= $this->chat;
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'default', 'project_key' => 'demo',
            'user_id' => $chat->user_id, 'conversation_id' => $chat->id, 'channel' => 'chat', 'actor_type' => 'user',
            'actor_id' => (string) $chat->user_id, 'locale' => 'it', 'timezone' => 'UTC', 'status' => 'running',
            'input_json' => ['question' => 'Informazioni su ORD-123', 'user_message_id' => $turn?->id]]);
    }

    private function source(array $record = ['id' => 'ORD-123', 'status' => 'processing', 'trackingCode' => 'TRACK-55'], string $toolName = self::TOOL): array
    {
        $turn = $this->chat->messages()->create(['role' => 'user', 'content' => 'ORD-123',
            'metadata' => ['reasoning' => ['understanding' => $this->understanding()->toArray()]]]);
        $run = $this->runFixture(turn: $turn);
        $definition = app(AgentToolRegistry::class)->forContext(AgentExecutionContext::fromArray($run->toArray()), $this->user)[$toolName];
        $execution = $run->toolExecutions()->create(['logical_index' => 1, 'tool_name' => $toolName, 'tool_kind' => $definition->kind, 'status' => 'completed']);
        $result = ['records' => [$record]];
        $tool = ['execution_id' => $execution->id, 'tool' => $toolName, 'kind' => $definition->kind,
            'executor_reference' => $definition->executorReference, 'arguments' => ['query' => 'ORD-123'], 'result' => $result,
            'evidence_hash' => hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE)), 'retrieved_at' => now()->toIso8601String()];
        $run->forceFill(['result_json' => ['evidence' => ['api_tools' => [$tool]]]])->save();
        $this->memory->consolidate($this->chat, $turn, ['api_tools' => [$tool]], [], $this->user);
        return [$tool, $turn];
    }

    private function fact(string $field): array
    {
        return collect($this->memory->projection($this->chat, $this->user, allowedTools: [self::TOOL]))->firstWhere('field', $field);
    }

    private function route(QuestionUnderstanding $understanding): QuestionUnderstanding
    {
        return $this->memory->resolve($this->chat, $understanding, $this->user, allowedTools: [self::TOOL]);
    }

    private function document(): array
    {
        $text = 'ORD-123: il cliente ha scelto una sostituzione.';
        $document = KnowledgeDocument::create(['project_key' => 'demo', 'source_type' => 'manual', 'source_path' => 'order.txt',
            'title' => 'Ordine ORD-123', 'content_hash' => hash('sha256', $text), 'status' => 'ready']);
        $chunk = KnowledgeChunk::create(['project_key' => 'demo', 'knowledge_document_id' => $document->id, 'chunk_order' => 0, 'chunk_text' => $text, 'chunk_hash' => hash('sha256', $text)]);
        $source = ['document_id' => $document->id, 'title' => $document->title, 'source_type' => 'manual', 'evidence' => [[
            'chunk_id' => $chunk->id, 'content' => $text, 'evidence_hash' => hash('sha256', $text), 'retrieved_at' => now()->toIso8601String(), 'complete_source' => true]]];
        $claim = ['text' => $text, 'quote' => $text, 'document_id' => $document->id, 'tool_execution_id' => null,
            'evidence_hash' => hash('sha256', $text), 'entity_identifiers' => ['ORD-123']];
        $turn = $this->chat->messages()->create(['role' => 'user', 'content' => 'ORD-123', 'metadata' => ['reasoning' => ['understanding' => $this->understanding()->toArray()]]]);
        $this->memory->consolidate($this->chat, $turn, ['documents' => [$source]], [$claim], $this->user);
        return [$document, $chunk, $source, $claim];
    }

    public function test_unpublished_exact_fields_are_consolidated_and_reused_without_a_new_tool_call(): void
    {
        $this->source();
        $this->travel(2)->minutes();
        $routed = $this->route($this->understanding([$this->fact('trackingCode')['fact_id']], ['trackingCode']));
        $this->assertFalse($routed->needsResearch());
        $this->assertSame('reuse', $routed->memoryResolution['tasks'][0]['decision']);
        $answer = $this->memory->answer($this->chat, $routed, $this->user, allowedTools: [self::TOOL]);
        $this->assertStringContainsString('TRACK-55', $answer->answer);
        $this->assertSame(1, \App\Models\AgentToolExecution::count());
        Http::assertNothingSent();
    }

    public function test_selected_authorized_facts_are_not_lost_when_another_source_fills_the_prompt_budget(): void
    {
        $this->document();
        $id = collect($this->memory->projection($this->chat, $this->user))->firstWhere('field', 'statement')['fact_id'];
        $this->travel(1)->seconds();
        $this->source(['id' => 'ORD-123', 'status' => 'processing', 'trackingCode' => 'TRACK-55',
            ...array_fill_keys(array_map(fn ($i) => 'telemetry_'.$i, range(1, 150)), 'unrequested')]);
        config(['reasoning.session_memory.max_prompt_facts' => 1]);
        $this->assertNotContains($id, array_column($this->memory->projection($this->chat, $this->user, allowedTools: [self::TOOL]), 'fact_id'));
        $routed = $this->route($this->understanding([$id], []));
        $this->assertFalse($routed->needsResearch());
        $this->assertSame([$id], $routed->memoryResolution['fact_ids']);
        $answer = $this->memory->answer($this->chat, $routed, $this->user, allowedTools: [self::TOOL]);
        $this->assertStringContainsString('sostituzione', $answer->answer);
        $this->assertCount(1, $this->memory->evidence($this->chat, $routed, $this->user, allowedTools: [self::TOOL])['documents']);
        $this->travel(6)->minutes();
        $expired = $this->route($this->understanding([$id], []));
        $this->assertTrue($expired->needsResearch());
        $this->assertSame('expired', $expired->memoryResolution['tasks'][0]['excluded'][0]['reason']);
    }

    public function test_a_large_record_does_not_starve_other_sources_or_its_verified_statement(): void
    {
        [, , $document, $claim] = $this->document();
        $this->travel(1)->seconds();
        [$tool, $turn] = $this->source(['id' => 'ORD-123', 'status' => 'processing',
            ...array_fill_keys(array_map(fn ($i) => 'telemetry_'.$i, range(1, 150)), 'noise')]);
        $statement = ['text' => 'ORD-123 has status processing.', 'quote' => json_encode($tool['result']['records'][0]),
            'tool_execution_id' => $tool['execution_id'], 'evidence_hash' => $tool['evidence_hash'], 'entity_identifiers' => ['ORD-123']];
        $this->memory->consolidate($this->chat, $turn, ['api_tools' => [$tool]], [$statement], $this->user);
        $projection = $this->memory->projection($this->chat, $this->user, allowedTools: [self::TOOL]);
        $statements = array_column(array_filter($projection, fn ($fact) => $fact['field'] === 'statement'), 'value');
        $this->assertContains($claim['text'], $statements);
        $this->assertContains($statement['text'], $statements);
        $this->assertLessThanOrEqual(11, count($projection));
    }

    public function test_only_a_still_authorized_case_link_selects_its_api_context(): void
    {
        [, $chunk, $source, $claim] = $this->document();
        $text = 'CASE-A references ORD-123. The customer requested a replacement.';
        $hash = hash('sha256', $text);
        $chunk->update(['chunk_text' => $text, 'chunk_hash' => $hash]);
        $source['evidence'][0] = [...$source['evidence'][0], 'content' => $text, 'evidence_hash' => $hash];
        $claim = [...$claim, 'text' => 'CASE-A references ORD-123.', 'quote' => $text,
            'evidence_hash' => $hash, 'entity_identifiers' => ['CASE-A', 'ORD-123']];
        $turn = $this->chat->messages()->create(['role' => 'user', 'content' => 'CASE-A',
            'metadata' => ['reasoning' => ['understanding' => $this->understanding()->toArray()]]]);
        $this->memory->consolidate($this->chat, $turn, ['documents' => [$source]], [$claim], $this->user);
        $this->travel(1)->seconds();
        $this->source();
        $facts = $this->memory->projection($this->chat, $this->user, allowedTools: [self::TOOL], question: 'Prepare a note for CASE-A.');
        $this->assertNotEmpty(array_filter($facts, fn ($fact) => str_starts_with($fact['namespace'], 'tool:')), json_encode(array_column($facts, 'namespace')));
        $chunk->update(['chunk_text' => 'This document changed; its earlier link is no longer authorized.']);
        $facts = $this->memory->projection($this->chat, $this->user, allowedTools: [self::TOOL], question: 'Prepare a note for CASE-A.');
        $this->assertSame([], $facts);
    }

    public function test_complete_facts_still_require_composition_for_a_new_requested_presentation(): void
    {
        $this->source();
        $understanding = QuestionUnderstanding::fromArray([
            ...$this->understanding([$this->fact('trackingCode')['fact_id']], ['trackingCode'])->toArray(),
            'requires_composition' => true,
        ]);
        $routed = $this->route($understanding);
        $this->assertFalse($routed->needsResearch(), 'Changing format alone must not refresh valid source data.');
        $this->assertTrue($routed->requiresComposition);
        $this->assertTrue($routed->forSubquestion(0)->requiresComposition);
        $this->assertTrue(QuestionUnderstanding::fromArray($routed->toArray())->requiresComposition);
        $this->assertNull($this->memory->answer($this->chat, $routed, $this->user, allowedTools: [self::TOOL]),
            'Concatenated verified text cannot replace a requested table or rewrite.');
        $sources = $this->memory->evidence($this->chat, $routed, $this->user, allowedTools: [self::TOOL]);
        $this->assertCount(1, $sources['api_tools'], 'The composer retains the original authorized source.');
        $this->assertSame(1, \App\Models\AgentToolExecution::count());
        Http::assertNothingSent();
    }

    public function test_reuse_and_reconsolidation_do_not_extend_the_original_clock(): void
    {
        $this->source();
        $original = SessionMemoryObservation::firstOrFail()->expires_at->toISOString();
        $this->travel(4)->minutes();
        $routed = $this->route($this->understanding([$this->fact('status')['fact_id']]));
        $evidence = $this->memory->evidence($this->chat, $routed, $this->user, allowedTools: [self::TOOL]);
        $turn = $this->chat->messages()->create(['role' => 'user', 'content' => 'Ancora ORD-123']);
        $this->memory->consolidate($this->chat, $turn, $evidence, [], $this->user);
        $this->assertSame(1, SessionMemoryObservation::count());
        $this->assertSame($original, SessionMemoryObservation::firstOrFail()->expires_at->toISOString());
        $this->travel(1)->minutes();
        $this->assertTrue($this->route($this->understanding([$this->fact('status')['fact_id']]))->needsResearch());
        $this->assertNull($this->memory->answer($this->chat, $routed, $this->user, allowedTools: [self::TOOL]));
    }

    public function test_ten_days_later_facts_expire_but_objective_and_navigation_survive(): void
    {
        $this->source();
        $this->chat->forceFill(['reasoning_state' => ['objective' => 'Seguire ORD-123', 'focus' => ['identifiers' => ['ORD-123']]]])->save();
        $id = $this->fact('status')['fact_id'];
        $this->travel(10)->days();
        $routed = $this->route($this->understanding([$id]));
        $this->assertTrue($routed->needsResearch());
        $this->assertSame('expired', $routed->memoryResolution['tasks'][0]['excluded'][0]['reason']);
        $this->assertSame('Seguire ORD-123', $this->chat->fresh()->reasoning_state['objective']);
        $this->assertSame(1, SessionMemoryObservation::count());
    }

    public function test_explicit_refresh_bypasses_fresh_memory_and_does_not_import_the_old_values(): void
    {
        $this->source();
        $routed = $this->route($this->understanding([$this->fact('status')['fact_id']], refresh: true));
        $this->assertTrue($routed->needsResearch());
        $this->assertSame([], $routed->memoryResolution['fact_ids']);
        $this->assertSame([], $this->memory->evidence($this->chat, $routed, $this->user, allowedTools: [self::TOOL])['api_tools']);
    }

    public function test_partial_request_reuses_status_and_searches_only_missing_tracking(): void
    {
        $this->source(['id' => 'ORD-123', 'status' => 'processing']);
        $routed = $this->route($this->understanding([$this->fact('status')['fact_id']], ['status', 'trackingCode'], complete: false));
        $this->assertTrue($routed->needsResearch());
        $this->assertSame('partial', $routed->memoryResolution['tasks'][0]['decision']);
        $this->assertSame(['Tracking di ORD-123'], $routed->kbQueries);
        $this->assertCount(1, $this->memory->evidence($this->chat, $routed, $this->user, allowedTools: [self::TOOL])['api_tools']);
    }

    public function test_claimed_complete_coverage_is_rejected_when_a_requested_field_is_missing(): void
    {
        $this->source();
        $routed = $this->route($this->understanding([$this->fact('status')['fact_id']], ['status', 'deliveryDate']));
        $this->assertTrue($routed->needsResearch());
    }

    public function test_historical_recap_can_use_expired_observations_with_the_acquisition_date(): void
    {
        $this->source();
        $id = $this->fact('status')['fact_id'];
        $date = now()->toIso8601String();
        $this->travel(10)->days();
        $routed = $this->route($this->understanding([$id], action: QuestionAction::Recap));
        $answer = $this->memory->answer($this->chat, $routed, $this->user, allowedTools: [self::TOOL]);
        $this->assertNotNull($answer);
        $this->assertStringContainsString($date, $answer->answer);
        $this->assertSame($date, SessionMemoryObservation::firstOrFail()->acquired_at->toIso8601String());
    }

    public function test_other_conversation_and_an_unknown_fact_cannot_supply_coverage(): void
    {
        $this->source();
        $id = $this->fact('status')['fact_id'];
        $other = Conversation::create(['user_id' => $this->user->id, 'project_key' => 'demo']);
        $routed = $this->memory->resolve($other, $this->understanding([$id]), $this->user, allowedTools: [self::TOOL]);
        $this->assertTrue($routed->needsResearch());
        $this->assertSame([], $routed->memoryResolution['fact_ids']);
        $this->assertTrue($this->route($this->understanding([str_repeat('a', 64)]))->needsResearch());
    }

    public function test_source_selection_and_changed_tool_payload_block_memory_reuse(): void
    {
        $this->source();
        $id = $this->fact('status')['fact_id'];
        $this->assertSame([], $this->memory->projection($this->chat, $this->user, allowedTools: []));
        $routed = $this->route($this->understanding([$id]));
        $run = $this->runFixture();
        $run->forceFill(['result_json' => ['question_understanding' => $routed->toArray()]])->save();
        $evidence = $this->memory->evidence($this->chat, $routed, $this->user, allowedTools: [self::TOOL]);
        $access = app(AgentEvidenceAccess::class);
        $this->assertCount(1, $access->current($run, AgentExecutionContext::fromArray($run->toArray()), $evidence)['api_tools']);
        $evidence['api_tools'][0]['result']['records'][0]['status'] = 'invented';
        $this->assertSame([], $access->current($run, AgentExecutionContext::fromArray($run->toArray()), $evidence)['api_tools']);
    }

    public function test_changed_document_and_revoked_project_access_invalidate_a_fresh_fact(): void
    {
        [, $chunk] = $this->document();
        $facts = $this->memory->projection($this->chat, $this->user);
        $this->assertCount(1, $facts);
        $routed = $this->memory->resolve($this->chat, $this->understanding([$facts[0]['fact_id']], ['*']), $this->user);
        $this->assertFalse($routed->needsResearch());
        $chunk->forceFill(['chunk_text' => 'ORD-123: il cliente ha scelto un rimborso.'])->save();
        $this->assertSame([], $this->memory->projection($this->chat, $this->user));
    }

    public function test_source_claims_need_not_appear_in_the_visible_answer_and_finalization_is_idempotent(): void
    {
        [, , $source, $claim] = $this->document();
        $turn = $this->chat->messages()->latest('id')->first();
        $answer = app(ConversationReasoning::class)->saveMessage($this->chat, $turn,
            ['role' => 'assistant', 'content' => 'Ho trovato il documento.', 'metadata' => ['grounding' => ['memory_claims' => [$claim]]]],
            ['documents' => [$source]], [$claim]);
        app(ConversationReasoning::class)->finish($this->chat, $turn, $answer, ['documents' => [$source]]);
        $this->assertSame(1, SessionMemoryFact::count());
        $this->assertSame(2, $this->chat->fresh()->reasoning_state['schema_version']);
    }

    public function test_answer_and_memory_roll_back_together_on_transaction_failure(): void
    {
        [$tool, $turn] = $this->source();
        SessionMemoryObservation::query()->delete();
        try {
            DB::transaction(function () use ($tool, $turn): void {
                app(ConversationReasoning::class)->saveMessage($this->chat, $turn,
                    ['role' => 'assistant', 'content' => 'processing'], ['api_tools' => [$tool]]);
                throw new \RuntimeException('Simulated commit failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated commit failure', $exception->getMessage());
        }
        $this->assertSame(0, $this->chat->messages()->where('role', 'assistant')->count());
        $this->assertSame(0, SessionMemoryObservation::count());
    }

    public function test_deleting_the_session_cascades_the_fact_archive(): void
    {
        $this->source();
        $this->chat->delete();
        $this->assertSame(0, SessionMemoryObservation::count());
        $this->assertSame(0, SessionMemoryFact::count());
    }

    public function test_rollback_flag_preserves_archived_facts_and_disables_reuse(): void
    {
        $this->source();
        $id = $this->fact('status')['fact_id'];
        config(['reasoning.session_memory.enabled' => false]);
        $this->assertSame([], $this->memory->projection($this->chat, $this->user, allowedTools: [self::TOOL]));
        $this->assertTrue($this->route($this->understanding([$id]))->needsResearch());
        $this->assertSame(3, SessionMemoryFact::count());
    }

    public function test_actor_and_tenant_scope_are_checked_before_loading_any_fact(): void
    {
        $this->source();
        app(TenantContext::class)->set('another-tenant');
        $this->expectException(\DomainException::class);
        $this->memory->projection($this->chat, $this->user, allowedTools: [self::TOOL]);
    }

    public function test_agent_loop_skips_all_research_for_a_verified_hit_in_both_coordinators(): void
    {
        $this->source();
        $id = $this->fact('status')['fact_id'];
        foreach ([false, true] as $waves) {
            config(['agent.waves.enabled' => $waves]);
            $turn = $this->chat->messages()->create(['role' => 'user', 'content' => 'Informazioni su ORD-123']);
            $routed = $this->route($this->understanding([$id]));
            $run = $this->runFixture(turn: $turn);
            $run->forceFill(['result_json' => ['question_understanding' => $routed->toArray()]])->save();
            $outcome = app(AgentLoop::class)->run($run, AgentExecutionContext::fromArray($run->toArray()));
            $this->assertSame('session_memory', $outcome->stopReason);
            $this->assertSame(0, $run->toolExecutions()->count());
        }
        Http::assertNothingSent();
    }

    private function expectInterpretation(array $ids, array $fields = ['*']): void
    {
        $understanding = $this->understanding($ids, $fields)->toArray();
        unset($understanding['available'], $understanding['failure_reason']);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturnUsing(function ($provider, $system, $messages) use ($understanding) {
            $input = json_decode($messages[0]['content'], true);
            $this->assertNotEmpty($input['reasoning_context']['verified_facts']);
            return new AiResponse(json_encode($understanding), 'fake', 'test');
        });
        $this->app->instance(AiManager::class, $ai);
    }

    public function test_sync_and_sse_answer_from_verified_documents_without_search_or_synthesis(): void
    {
        $this->document();
        $id = $this->memory->projection($this->chat, $this->user)[0]['fact_id'];
        foreach ([MessageController::class, MessageStreamController::class] as $index => $controller) {
            $this->expectInterpretation([$id]);
            Route::post('/verified-memory-'.$index.'/{conversation}', [$controller, 'store'])->middleware(SubstituteBindings::class);
            $response = $this->actingAs($this->user)->postJson('/verified-memory-'.$index.'/'.$this->chat->id, ['content' => 'Informazioni su ORD-123']);
            $response->assertOk();
            if ($index === 0) $response->assertJsonPath('metadata.model', 'verified-session-memory')->assertJsonPath('metadata.search_stats.kb_searches', 0);
            else $this->assertStringContainsString('sostituzione', $response->streamedContent());
        }
        Http::assertNothingSent();
    }

    public function test_durable_handler_completes_memory_before_publishing_terminal_event_and_retry_does_not_duplicate(): void
    {
        $this->source();
        $id = $this->fact('status')['fact_id'];
        $this->expectInterpretation([$id], ['status']);
        $turn = $this->chat->messages()->create(['role' => 'user', 'content' => 'Informazioni su ORD-123']);
        $run = $this->runFixture(turn: $turn);
        app(AgentRunHandler::class)->handle($run);
        $run->refresh();
        $this->assertSame('completed', $run->status, $run->error_code ?? '');
        $this->assertSame('session_memory', $run->result_json['stop_reason']);
        $saved = $this->chat->messages()->where('agent_run_id', $run->id)->firstOrFail();
        $this->assertNotNull(data_get($saved->metadata, 'reasoning.revision'));
        $this->assertSame(['kb_searches' => 0, 'tool_calls' => 0], $saved->metadata['search_stats']);
        $this->assertSame(1, SessionMemoryObservation::count());
        app(AgentRunHandler::class)->handle($run->fresh());
        $this->assertSame(1, $this->chat->messages()->where('agent_run_id', $run->id)->count());
        Http::assertNothingSent();
    }

    private function api(string $name): \Padosoft\AskMyDocsConnectorApi\Models\ApiRoute
    {
        $connector = \Padosoft\AskMyDocsConnectorApi\Models\ApiConnector::create(['tenant_id' => 'default',
            'project_key' => 'demo', 'name' => $name, 'is_active' => true]);
        return \Padosoft\AskMyDocsConnectorApi\Models\ApiRoute::create(['tenant_id' => 'default', 'api_connector_id' => $connector->id,
            'project_key' => 'demo', 'name' => $name, 'slug' => $name, 'http_method' => 'GET', 'url' => 'https://example.test/orders',
            'mode' => 'tool', 'status' => 'active', 'endpoint_type' => 'detail',
            'tool_definition' => ['name' => $name, 'description' => 'Read orders', 'input_schema' => ['type' => 'object', 'properties' => []]]]);
    }

    public function test_real_api_catalog_revocation_and_live_source_selection_are_enforced_on_every_reuse(): void
    {
        $route = $this->api('memory_orders');
        $this->source(toolName: 'memory_orders');
        $facts = $this->memory->projection($this->chat, $this->user, allowedTools: ['memory_orders']);
        $id = collect($facts)->firstWhere('field', 'status')['fact_id'];
        $routed = $this->memory->resolve($this->chat, $this->understanding([$id]), $this->user, allowedTools: ['memory_orders']);
        $run = $this->runFixture();
        $run->forceFill(['result_json' => ['question_understanding' => $routed->toArray()]])->save();
        $evidence = $this->memory->evidence($this->chat, $routed, $this->user, allowedTools: ['memory_orders']);
        $this->assertCount(1, app(AgentEvidenceAccess::class)->current($run, AgentExecutionContext::fromArray($run->toArray()), $evidence)['api_tools']);
        $run->forceFill(['input_json' => [...$run->input_json, 'live_sources' => ['api' => []]]])->save();
        $this->assertSame([], app(AgentEvidenceAccess::class)->current($run, AgentExecutionContext::fromArray($run->toArray()), $evidence)['api_tools']);
        $route->forceFill(['status' => 'disabled'])->save();
        $this->assertSame([], $this->memory->projection($this->chat, $this->user, allowedTools: ['memory_orders']));
    }

    public function test_equal_codes_in_different_api_namespaces_do_not_merge_or_silently_resolve_a_conflict(): void
    {
        $this->api('memory_orders_a');
        $this->api('memory_orders_b');
        $this->source(['id' => 'ORD-123', 'status' => 'processing'], 'memory_orders_a');
        $this->source(['id' => 'ORD-123', 'status' => 'cancelled'], 'memory_orders_b');
        $facts = $this->memory->projection($this->chat, $this->user, allowedTools: ['memory_orders_a', 'memory_orders_b']);
        $ids = collect($facts)->where('field', 'status')->pluck('fact_id')->all();
        $this->assertCount(2, $ids);
        $routed = $this->memory->resolve($this->chat, $this->understanding($ids), $this->user, allowedTools: ['memory_orders_a', 'memory_orders_b']);
        $this->assertTrue($routed->needsResearch());
        $this->assertSame([], $routed->memoryResolution['fact_ids']);
        $this->assertSame('conflicting_sources', $routed->memoryResolution['tasks'][0]['excluded'][0]['reason']);
        $single = $this->memory->resolve($this->chat, $this->understanding([$ids[0]]), $this->user, allowedTools: ['memory_orders_a', 'memory_orders_b']);
        $this->assertTrue($single->needsResearch(), 'Selecting one fact must not hide a conflicting namespace.');
    }

    public function test_a_partial_record_collection_does_not_cover_a_request_for_all_records(): void
    {
        $this->source();
        $understanding = $this->understanding([$this->fact('status')['fact_id']])->toArray();
        $understanding['memory_decisions'][0]['collection'] = true;
        $understanding['memory_decisions'][0]['scope_matches'] = true;
        $routed = $this->route(QuestionUnderstanding::fromArray($understanding));
        $this->assertTrue($routed->needsResearch());
    }

    public function test_a_newer_observation_supersedes_an_older_value_without_rewriting_the_archive(): void
    {
        $this->source(['id' => 'ORD-123', 'status' => 'processing']);
        $this->travel(1)->minutes();
        $this->source(['id' => 'ORD-123', 'status' => 'shipped']);
        $this->assertSame('shipped', $this->fact('status')['value']);
        $this->assertSame(2, SessionMemoryFact::query()->where('field', 'status')->count());
    }

    public function test_prompt_compaction_does_not_delete_archive_and_explicit_identifiers_find_older_relevant_facts(): void
    {
        $this->source();
        $this->chat->forceFill(['reasoning_state' => ['focus' => ['identifiers' => ['OTHER']]]])->save();
        config(['reasoning.session_memory.max_prompt_facts' => 1]);
        $this->assertCount(1, $this->memory->projection($this->chat, $this->user, allowedTools: [self::TOOL], question: 'ORD-123'));
        $this->assertSame(3, SessionMemoryFact::count());
        $this->assertSame(3, count($this->memory->snapshot($this->chat, $this->user)['observations'][0]['facts']));
    }

    public function test_widget_uses_the_same_verified_document_memory_without_a_new_search(): void
    {
        [, , $source, $claim] = $this->document();
        $key = \App\Models\WidgetKey::create(['tenant_id' => 'default', 'project_key' => 'demo', 'public_key' => 'pk_verified_memory',
            'allowed_origins' => ['https://example.test'], 'rate_limit' => 100, 'skill' => 'askmydocs-assistant@1', 'is_active' => true]);
        $session = \App\Models\WidgetSession::create(['tenant_id' => 'default', 'widget_key_id' => $key->id,
            'project_key' => 'demo', 'public_session_id' => (string) Str::uuid(), 'status' => 'active', 'skill' => 'askmydocs-assistant@1', 'locale' => 'it']);
        $turn = $session->steps()->create(['step_index' => 0, 'kind' => 'user_message', 'args_json' => ['content' => 'ORD-123',
            'reasoning' => ['understanding' => $this->understanding()->toArray()]]]);
        $this->memory->consolidate($session, $turn, ['documents' => [$source]], [$claim]);
        $fact = $this->memory->projection($session)[0];
        $this->expectInterpretation([$fact['fact_id']]);
        config(['ai.default' => 'fake', 'chat-log.enabled' => false]);
        $response = app(\App\Services\Widget\WidgetOrchestratorService::class)->step($session, [], 'Informazioni su ORD-123', null);
        $this->assertStringContainsString('sostituzione', $response['answer']);
        $this->assertSame('verified_session_memory', $session->steps()->latest('id')->first()->args_json['grounding']['model']);
        $this->assertCount(1, $this->memory->snapshot($session)['observations']);
        Http::assertNothingSent();
    }

    public function test_unpublished_narrative_candidates_use_an_independent_grounding_decision(): void
    {
        [, , $source, $claim] = $this->document();
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8, 'decisions.default' => 'jev',
            'decisions.models.jev.driver' => \App\Decisions\Models\JevDecisionModel::class]);
        Http::fake(['openrouter.ai/*' => fn ($request) => Http::response(['model' => 'jev-test', 'answers' => isset($request['state']['original_question'])
            ? ['relevance_0' => ['type' => 'noul', 'noul' => .99]] : [
            'claim_0' => ['type' => 'choice', 'choice' => 'supported_relevant', 'confidence' => .99,
                'probabilities' => ['supported_relevant' => .99, 'supported_off_topic' => 0, 'unsupported' => .01, 'conflicting' => 0, 'insufficient_context' => 0]],
        ]])]);
        $run = $this->runFixture();
        $verified = app(\App\Services\Chat\Reasoning\SessionMemoryConsolidator::class)->verify(['documents' => [$source], 'api_tools' => []],
            [$claim], $this->understanding(fields: ['*']), 'default', 'demo', $run->run_id);
        $this->assertCount(1, $verified, json_encode($run->fresh()->result_json));
        $this->assertArrayHasKey('session_memory_decision', $run->fresh()->result_json);
        $this->assertArrayNotHasKey('focused_decision', $run->fresh()->result_json);
        $this->assertArrayNotHasKey('original_question_relevance_decision', $run->fresh()->result_json);
        (new \ReflectionMethod(AgentLoop::class, 'checkpoint'))->invoke(app(AgentLoop::class), $run,
            app(\App\Agent\Evidence\AgentEvidenceFactory::class)->empty(), [], [], true);
        $again = app(\App\Services\Chat\Reasoning\SessionMemoryConsolidator::class)->verify(['documents' => [$source], 'api_tools' => []],
            [$claim], $this->understanding(fields: ['*']), 'default', 'demo', $run->run_id);
        $this->assertCount(1, $again);
        Http::assertSentCount(2);
    }

    public function test_expiration_during_a_turn_cannot_fall_back_to_context_only_api_access(): void
    {
        $this->source();
        $routed = $this->route($this->understanding([$this->fact('status')['fact_id']]));
        $evidence = $this->memory->evidence($this->chat, $routed, $this->user, allowedTools: [self::TOOL]);
        $run = $this->runFixture();
        $run->forceFill(['result_json' => ['question_understanding' => $routed->toArray()]])->save();
        $this->travel(5)->minutes();
        $this->assertSame([], app(AgentEvidenceAccess::class)->current($run, AgentExecutionContext::fromArray($run->toArray()), $evidence)['api_tools']);
    }

    public function test_consolidation_rejects_a_turn_owned_by_another_session(): void
    {
        [, $turn] = $this->source();
        $other = Conversation::create(['user_id' => $this->user->id, 'project_key' => 'demo']);
        $this->expectException(\DomainException::class);
        $this->memory->consolidate($other, $turn, [], [], $this->user);
    }

    public function test_credential_bearing_payloads_are_not_duplicated_as_archived_facts(): void
    {
        $this->source(['id' => 'ORD-123', 'status' => 'processing', 'password' => 'not-for-session-memory']);
        $this->assertSame(0, SessionMemoryObservation::count());
        $this->assertSame(0, SessionMemoryFact::count());
    }
}
