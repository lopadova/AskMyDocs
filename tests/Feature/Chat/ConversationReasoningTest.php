<?php

namespace Tests\Feature\Chat;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\Reasoning\ConversationReasoning;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ConversationReasoningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        config(['reasoning.enabled' => true]);
        Cache::flush();
    }

    private function conversation(): Conversation
    {
        $user = User::create(['name' => 'Tester', 'email' => uniqid().'@example.test', 'password' => bcrypt('test')]);
        return Conversation::create(['user_id' => $user->id, 'project_key' => 'demo']);
    }

    private function interpretation(string $identifier, bool $followup = false): array
    {
        $focus = ['topic' => 'shipment', 'identifiers' => [$identifier], 'aspect' => 'status', 'fields' => ['status']];
        return ['language' => 'it', 'intent' => 'Stato della spedizione '.$identifier, 'action' => 'research', 'kb_queries' => [$identifier],
            'mentions' => $followup ? [] : [['text' => $identifier, 'type' => 'identifier']], 'references_previous_turn' => $followup,
            'transition' => $followup ? 'continue' : 'new', 'focus' => $focus, 'subquestions' => [$focus],
            'resolved_references' => $followup ? [$identifier] : [], 'needs_clarification' => false, 'clarification' => ''];
    }

    private function ai(array ...$replies): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->times(count($replies))->andReturn(...array_map(fn ($data) => new AiResponse(
            content: json_encode($data), provider: 'openrouter', model: 'test', promptTokens: 10, completionTokens: 10,
        ), $replies));
        $this->app->instance(AiManager::class, $ai);
    }

    public function test_reopening_and_retry_use_one_interpretation_and_keep_resolved_references_separate(): void
    {
        $this->ai($this->interpretation('TRACK-55'), $this->interpretation('TRACK-55', true));
        $conversation = $this->conversation();
        $first = $conversation->messages()->create(['role' => 'user', 'content' => 'TRACK-55']);
        $memory = app(ConversationReasoning::class);
        $one = $memory->prepare($conversation, $first, 'TRACK-55');
        $this->assertTrue($one->available);
        $memory->prepare($conversation, $first, 'TRACK-55');
        $next = $conversation->messages()->create(['role' => 'user', 'content' => 'ora la spedizione']);
        $two = $memory->prepare($conversation->fresh(), $next, 'ora la spedizione');
        $this->assertSame([], $two->mentions);
        $this->assertSame(['TRACK-55'], $two->resolvedReferences);
        $this->assertSame(['TRACK-55'], $conversation->fresh()->reasoning_state['focus']['identifiers']);
    }

    public function test_invented_context_reference_leaves_previous_focus_unchanged(): void
    {
        $this->ai($this->interpretation('TRACK-55'), $this->interpretation('INVENTED', true));
        $conversation = $this->conversation();
        $memory = app(ConversationReasoning::class);
        $turn = $conversation->messages()->create(['role' => 'user', 'content' => 'TRACK-55']);
        $memory->prepare($conversation, $turn, 'TRACK-55');
        $next = $conversation->messages()->create(['role' => 'user', 'content' => 'dettagli']);
        $result = $memory->prepare($conversation, $next, 'dettagli');
        $this->assertFalse($result->available);
        $this->assertSame(['TRACK-55'], $conversation->fresh()->reasoning_state['focus']['identifiers']);
    }

    public function test_source_action_and_server_offers_survive_reopening_without_reinterpreting_or_copying_answer_text(): void
    {
        $conversation = $this->conversation();
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'PRIVATE-FACT not navigation context.',
            'metadata' => ['grounding' => ['offered_actions' => ['read_source', 'not_a_valid_action']]]]);
        $turn = $conversation->messages()->create(['role' => 'user', 'content' => 'Aprila']);
        $interpretation = $this->interpretation('TRACK-55');
        $interpretation['action'] = 'read_source';
        $interpretation['mentions'] = [];
        $interpretation['focus']['identifiers'] = [];
        $interpretation['subquestions'][0]['identifiers'] = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->withArgs(function ($provider, $prompt, $messages) {
            $ctx = json_decode($messages[0]['content'], true)['reasoning_context'];
            $this->assertSame(['read_source'], $ctx['dialogue'][0]['offered_actions']);
            $this->assertStringNotContainsString('PRIVATE-FACT', json_encode($ctx));
            return true;
        })->andReturn(new AiResponse(json_encode($interpretation), 'fake', 'test'));
        $this->app->instance(AiManager::class, $ai);
        $memory = app(ConversationReasoning::class);
        $this->assertTrue($memory->prepare($conversation, $turn, 'Aprila')->asksToReadSource());
        $this->assertTrue($memory->prepare($conversation->fresh(), $turn->fresh(), 'Aprila')->asksToReadSource());
        $this->assertSame('read_source', data_get($turn->fresh()->metadata, 'reasoning.understanding.action'));
    }

    public function test_late_turn_cannot_overwrite_new_focus_and_finish_is_idempotent(): void
    {
        $this->ai($this->interpretation('TRACK-NEW'), $this->interpretation('TRACK-OLD'));
        $conversation = $this->conversation();
        $old = $conversation->messages()->create(['role' => 'user', 'content' => 'TRACK-OLD']);
        $new = $conversation->messages()->create(['role' => 'user', 'content' => 'TRACK-NEW']);
        $memory = app(ConversationReasoning::class);
        $memory->prepare($conversation, $new, 'TRACK-NEW');
        $memory->prepare($conversation, $old, 'TRACK-OLD');
        $answer = $conversation->messages()->create(['role' => 'assistant', 'content' => 'A fact.']);
        $claim = ['text' => 'A fact.', 'evidence_hash' => 'h', 'fact_id' => 'fact'];
        $memory->finish($conversation, $old, $answer, [], [$claim]);
        $revision = $conversation->reasoning_state['revision'];
        $memory->finish($conversation, $old, $answer, [], [$claim]);
        $this->assertSame($revision, $conversation->fresh()->reasoning_state['revision']);
        $this->assertSame(['TRACK-NEW'], $conversation->reasoning_state['focus']['identifiers']);
        $this->assertCount(1, $conversation->reasoning_state['communicated']);
    }

    public function test_other_conversation_cannot_supply_turn_or_memory(): void
    {
        $one = $this->conversation();
        $two = $this->conversation();
        $turn = $one->messages()->create(['role' => 'user', 'content' => 'private']);
        $this->expectException(\DomainException::class);
        app(ConversationReasoning::class)->prepare($two, $turn, 'private');
    }

    public function test_state_is_not_mass_assignable_and_revoked_references_are_not_exposed(): void
    {
        $conversation = $this->conversation();
        $conversation->fill(['reasoning_state' => ['focus' => 'forged']]);
        $this->assertNull($conversation->reasoning_state);
        $conversation->forceFill(['reasoning_state' => ['evidence' => [['kind' => 'document', 'document_id' => 999999, 'chunk_id' => 99,
            'identifiers' => ['SECRET'], 'relations' => [['to' => 'SECRET']]]]]])->save();
        $context = app(ConversationReasoning::class)->context($conversation);
        $this->assertSame([], $context['known_identifiers']);
        $this->assertSame([], $context['verified_relations']);
    }

    public function test_widget_uses_the_same_state_and_tool_continuation_reuses_user_step(): void
    {
        $this->ai($this->interpretation('TRACK-55'));
        $key = \App\Models\WidgetKey::create(['tenant_id' => 'default', 'project_key' => 'demo', 'public_key' => 'pk_reasoning',
            'allowed_origins' => ['https://example.test'], 'rate_limit' => 100, 'skill' => 'askmydocs-assistant@1', 'is_active' => true]);
        $session = \App\Models\WidgetSession::create(['tenant_id' => 'default', 'widget_key_id' => $key->id, 'project_key' => 'demo',
            'public_session_id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'active', 'skill' => 'askmydocs-assistant@1', 'locale' => 'it']);
        $turn = $session->steps()->create(['step_index' => 0, 'kind' => 'user_message', 'args_json' => ['content' => 'TRACK-55']]);
        $memory = app(ConversationReasoning::class);
        $memory->prepare($session, $turn, 'TRACK-55');
        $memory->prepare($session->fresh(), $turn->fresh(), 'TRACK-55');
        $answer = $session->steps()->create(['step_index' => 1, 'kind' => 'bot_message', 'args_json' => ['content' => 'Done']]);
        $memory->finish($session, $turn, $answer, []);
        $this->assertSame(['TRACK-55'], $session->fresh()->reasoning_state['focus']['identifiers']);
        $this->assertNotNull(data_get($answer->fresh()->args_json, 'reasoning.turn'));
        $this->assertSame(['user', 'assistant'], array_column($memory->context($session)['dialogue'], 'role'));
        $this->assertSame('Stato della spedizione TRACK-55', $session->fresh()->reasoning_state['objective']);
    }

    public function test_real_goal_and_ordered_tasks_are_persisted_and_followup_uses_only_earlier_dialogue(): void
    {
        $conversation = $this->conversation();
        $first = $conversation->messages()->create(['role' => 'user', 'content' => 'spedizone TRACK-55 detagli']);
        $initial = $this->interpretation('TRACK-55');
        $initial['subquestions'][0] += ['question' => 'Quali sono i dettagli della spedizione TRACK-55?', 'kb_queries' => ['TRACK-55 dettagli']];
        $followup = $this->interpretation('TRACK-55', true);
        $followup['subquestions'][0] += ['question' => 'Quali ulteriori dettagli sono disponibili sulla spedizione TRACK-55?', 'kb_queries' => ['TRACK-55 dettagli aggiuntivi']];
        $ai = Mockery::mock(AiManager::class);
        $calls = 0;
        $ai->shouldReceive('chatWithProvider')->twice()->andReturnUsing(function ($provider, $system, $messages) use (&$calls, $initial, $followup) {
            if ($calls++ === 1) {
                $ctx = json_decode($messages[0]['content'], true)['reasoning_context'];
                $this->assertSame('Stato della spedizione TRACK-55', $ctx['objective']);
                $this->assertSame(['user', 'assistant'], array_column($ctx['dialogue'], 'role'));
                $this->assertSame('spedizone TRACK-55 detagli', $ctx['dialogue'][0]['text']);
                $this->assertStringNotContainsString('FUTURE-SECRET', json_encode($ctx));
                $this->assertStringNotContainsString('untrusted assistant factual text', json_encode($ctx));
            }
            return new AiResponse(json_encode($calls === 1 ? $initial : $followup), 'fake', 'test');
        });
        $this->app->instance(AiManager::class, $ai);
        $memory = app(ConversationReasoning::class);
        $memory->prepare($conversation, $first, $first->content);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'untrusted assistant factual text', 'metadata' => ['completeness' => 'partial']]);
        $next = $conversation->messages()->create(['role' => 'user', 'content' => 'quindi?']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'FUTURE-SECRET']);
        $result = $memory->prepare($conversation->fresh(), $next, 'quindi?');
        $this->assertTrue($result->available);
        $this->assertSame(['TRACK-55 dettagli aggiuntivi'], $result->forSubquestion(0)->kbQueries);
        $this->assertSame([], $result->mentions);
        $this->assertSame(['TRACK-55'], $result->resolvedReferences);
        $this->assertSame($followup['subquestions'][0]['question'], $result->forSubquestion(0)->intent);
    }
}
