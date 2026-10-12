<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentAnswerPresenter;
use App\Agent\AgentExecutionContext;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class AgentAnswerPresentationMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_durable_replay_reuses_generation_and_verification_and_rejects_changed_facts(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        app(\App\Support\TenantContext::class)->set('default');
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'default', 'project_key' => 'demo',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => '1', 'locale' => 'it', 'timezone' => 'UTC',
            'status' => 'running', 'input_json' => ['question' => 'Costo?']]);
        $context = new AgentExecutionContext($run->run_id, 'default', 'demo', 'chat', 'user', '1', 'it', 'UTC');
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['id' => 'decision', 'model' => 'jev-test', 'answers' => [
            'faithful_0' => ['type' => 'noul', 'noul' => .99], 'complete_0' => ['type' => 'noul', 'noul' => .99],
        ]]));
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse('', 'fake', 'luna-test', toolCalls: [[
            'name' => 'present_verified_answer', 'arguments' => ['paragraphs' => [['text' => 'Il cambio costa 13 €.', 'claim_ids' => [0], 'causal_links' => []]]],
        ]]));
        $ai->shouldReceive('chat')->once()->andReturn(new AiResponse('', 'fake', 'luna-parser', toolCalls: [[
            'name' => 'extract_answer_relations', 'arguments' => ['links' => []],
        ]]));
        $service = new AgentAnswerPresenter($ai);
        $claims = [['text' => 'Il cambio costa 13 euro.', 'document_id' => 9, 'evidence_hash' => 'hash']];
        $one = $service->present('Costo?', $context, $claims, $run->run_id);
        $two = $service->present('Costo?', $context, $claims, $run->run_id);
        $this->assertSame('accepted', $one['trace']['status']);
        $this->assertSame($one['paragraphs'], $two['paragraphs']);
        $this->assertSame('completed', $run->fresh()->result_json['answer_presentation_decision']['status']);
        $this->assertSame('completed', $run->fresh()->result_json['answer_presentation_causal_extraction']['status']);
        $claims[0]['text'] = 'Il cambio costa 20 euro.';
        $this->assertNull($service->present('Costo?', $context, $claims, $run->run_id)['paragraphs']);
        Http::assertSentCount(1);
    }
}
