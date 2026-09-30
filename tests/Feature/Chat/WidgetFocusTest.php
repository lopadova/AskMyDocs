<?php

namespace Tests\Feature\Chat;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\WidgetKey;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Services\Widget\WidgetOrchestratorService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WidgetFocusTest extends TestCase
{
    use RefreshDatabase;

    private function key(): WidgetKey
    {
        app(TenantContext::class)->set('default');
        config(['reasoning.enabled' => true, 'ai.default' => 'fake', 'chat-log.enabled' => false]);
        return WidgetKey::create(['tenant_id' => 'default', 'project_key' => 'demo', 'public_key' => 'pk_focus',
            'allowed_origins' => ['https://example.test'], 'rate_limit' => 100, 'skill' => 'askmydocs-assistant@1', 'is_active' => true]);
    }

    private function understanding(bool $clarify = false): AiResponse
    {
        $focus = ['topic' => 'page', 'identifiers' => [], 'aspect' => 'read', 'fields' => []];
        return new AiResponse(content: json_encode(['language' => 'it', 'intent' => 'Read page', 'kb_queries' => ['page'], 'mentions' => [],
            'action' => $clarify ? 'clarify' : 'research',
            'references_previous_turn' => false, 'transition' => 'new', 'focus' => $focus, 'subquestions' => [$focus],
            'resolved_references' => [], 'needs_clarification' => $clarify, 'clarification' => $clarify ? 'Quale pagina?' : '']), provider: 'fake', model: 'test');
    }

    public function test_widget_clarification_does_not_call_retrieval_or_answer_model(): void
    {
        $key = $this->key();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->understanding(true));
        $ai->shouldNotReceive('chatWithHistory');
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $retrieval->shouldNotReceive('retrieve');
        $this->app->instance(AiManager::class, $ai);
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $answer = app(WidgetOrchestratorService::class)->start($key, [], 'Quale?', null, null);
        $this->assertSame('Quale pagina?', $answer['answer']);
    }

    public function test_tool_continuation_interprets_once_and_only_final_answer_finishes_memory(): void
    {
        $key = $this->key();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->understanding());
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            new AiResponse(content: 'Controllo.', provider: 'fake', model: 'test', toolCalls: [['name' => 'read_page', 'arguments' => '{}']]),
            new AiResponse(content: 'Ho letto la pagina.', provider: 'fake', model: 'test'));
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $retrieval->shouldReceive('shouldRefuse')->once()->andReturn(true);
        $this->app->instance(AiManager::class, $ai);
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $service = app(WidgetOrchestratorService::class);
        $service->start($key, ['page' => ['url' => 'https://example.test']], 'Leggi la pagina', null, null);
        $session = \App\Models\WidgetSession::firstOrFail();
        $this->assertSame('research', data_get($session->steps()->where('kind', 'user_message')->firstOrFail()->args_json, 'reasoning.understanding.action'));
        $this->assertEmpty($session->reasoning_state['finished_turns']);
        $service->step($session, [], null, ['tool' => 'read_page', 'ok' => true, 'artifact' => ['title' => 'Page']]);
        $this->assertCount(1, $session->fresh()->reasoning_state['finished_turns']);
    }
}
