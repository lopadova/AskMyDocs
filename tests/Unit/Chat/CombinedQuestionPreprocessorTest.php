<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Chat\ChatQuestionPreprocessor;
use App\Services\Chat\QuestionUnderstanding;
use Mockery;
use Tests\TestCase;

final class CombinedQuestionPreprocessorTest extends TestCase
{
    private function planning(): array
    {
        return ['schema' => ['type' => 'object'], 'instructions' => 'Plan authorized tools.', 'tools' => [['name' => 'lookup']]];
    }

    private function payload(): array
    {
        return ['language' => 'it', 'intent' => 'Stato ordine', 'action' => 'research', 'kb_queries' => ['ordine'],
            'mentions' => [], 'references_previous_turn' => false, 'context_mode' => 'new', 'requires_research' => true];
    }

    public function test_combines_planning_and_restores_opaque_arguments_without_caching_plan(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturnUsing(function ($provider, $system, $messages, $options) {
            $input = json_decode($messages[0]['content'], true);
            $this->assertStringContainsString('opaque_reference_0', $input['question']);
            $this->assertSame([['name' => 'lookup']], $input['available_tools']);
            $this->assertStringContainsString('Plan authorized tools.', $system);
            $this->assertArrayHasKey('initial_plan', $options['response_format']['json_schema']['schema']['properties']);
            return new AiResponse(json_encode([...$this->payload(), 'initial_plan' => ['actions' => [['arguments_json' => '{"id":"opaque_reference_0"}']]]]), 'fake', 'fake');
        });
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Stato ordine 3331234567', initialPlanning: $this->planning());
        $this->assertTrue($result->available);
        $this->assertSame('{"id":"3331234567"}', $result->initialPlan['actions'][0]['arguments_json']);
        $this->assertArrayNotHasKey('initial_plan', $result->toArray());
        $this->assertNull(QuestionUnderstanding::fromArray($result->toArray())->initialPlan);
    }

    public function test_missing_or_malformed_plan_does_not_retry_valid_interpretation(): void
    {
        foreach ([null, 'invalid'] as $plan) {
            $ai = Mockery::mock(AiManager::class);
            $payload = $this->payload();
            if ($plan !== null) $payload['initial_plan'] = $plan;
            $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse(json_encode($payload), 'fake', 'fake'));
            $result = (new ChatQuestionPreprocessor($ai))->interpret('Stato ordine', initialPlanning: $this->planning());
            $this->assertTrue($result->available);
            $this->assertNull($result->initialPlan);
        }
    }

    public function test_non_research_turns_never_keep_a_plan(): void
    {
        foreach (['greeting', 'clarify', 'provenance'] as $action) {
            $ai = Mockery::mock(AiManager::class);
            $payload = [...$this->payload(), 'action' => $action, 'intent' => 'Ciao', 'kb_queries' => [],
                'requires_research' => false, 'initial_plan' => ['actions' => []]];
            $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse(json_encode($payload), 'fake', 'fake'));
            $result = (new ChatQuestionPreprocessor($ai))->interpret('Ciao', initialPlanning: $this->planning());
            $this->assertTrue($result->available);
            $this->assertNull($result->initialPlan);
        }
    }

    public function test_restored_primary_task_discards_a_plan_based_on_incomplete_focus(): void
    {
        $hub = ['topic' => 'hub', 'identifiers' => ['HUB-AA-01'], 'aspect' => 'details', 'fields' => []];
        $shipment = ['topic' => 'shipment', 'identifiers' => ['TRACK-55'], 'aspect' => 'details', 'fields' => []];
        $payload = [...$this->payload(), 'intent' => 'Hub e spedizione', 'kb_queries' => ['HUB-AA-01', 'TRACK-55'],
            'mentions' => [['text' => 'HUB-AA-01', 'type' => 'identifier'], ['text' => 'TRACK-55', 'type' => 'identifier']],
            'transition' => 'new', 'focus' => $hub, 'subquestions' => [$shipment], 'resolved_references' => [],
            'needs_clarification' => false, 'clarification' => '', 'initial_plan' => ['actions' => []]];
        unset($payload['context_mode'], $payload['requires_research']);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse(json_encode($payload), 'fake', 'fake'));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Informazioni su HUB-AA-01 e TRACK-55', reasoningContext: [], initialPlanning: $this->planning());
        $this->assertTrue($result->available);
        $this->assertSame(['hub', 'shipment'], array_column($result->subquestions, 'topic'));
        $this->assertNull($result->initialPlan);
    }

    public function test_unchanged_focus_keeps_combined_plan(): void
    {
        $focus = ['topic' => 'hub', 'identifiers' => ['HUB-A'], 'aspect' => 'details', 'fields' => []];
        $sub = [...$focus, 'question' => 'Details HUB-A', 'kb_queries' => ['HUB-A'], 'context_mode' => 'new', 'requires_research' => true];
        $payload = [...$this->payload(), 'kb_queries' => ['HUB-A'], 'mentions' => [['text' => 'HUB-A', 'type' => 'identifier']],
            'transition' => 'new', 'focus' => $focus, 'subquestions' => [$sub], 'resolved_references' => [],
            'needs_clarification' => false, 'clarification' => '', 'initial_plan' => ['actions' => []]];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse(json_encode($payload), 'fake', 'fake'));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Details HUB-A', reasoningContext: [], initialPlanning: $this->planning());
        $this->assertTrue($result->available);
        $this->assertSame(['actions' => []], $result->initialPlan);
    }

    public function test_normal_contract_has_no_planning_fields(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturnUsing(function ($provider, $system, $messages, $options) {
            $this->assertArrayNotHasKey('available_tools', json_decode($messages[0]['content'], true));
            $this->assertArrayNotHasKey('initial_plan', $options['response_format']['json_schema']['schema']['properties']);
            return new AiResponse(json_encode($this->payload()), 'fake', 'fake');
        });
        $this->assertTrue((new ChatQuestionPreprocessor($ai))->interpret('Stato ordine')->available);
    }
}
