<?php

declare(strict_types=1);

namespace Tests\Unit\Decisions;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\AgentRun;
use Tests\TestCase;

final class SelectiveRepairTest extends TestCase
{
    use RefreshDatabase;
    private function setupDecisions(): void
    {
        config(['reasoning.selective_validation' => true, 'reasoning.selective_repair' => true,
            'ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                if ($key === 'covered') {
                    $answers[$key] = ['type' => 'noul', 'noul' => $request['state']['answer'] === [] ? .01 : .99];
                } elseif ($question['type'] === 'noul') {
                    $answers[$key] = ['type' => 'noul', 'noul' => .99];
                } else {
                    $pair = collect($request['state']['pairs'])->firstWhere('claim_id', $key);
                    $category = str_contains($pair['claim'], 'paid') ? 'unsupported' : 'supported_relevant';
                    $probabilities = array_fill_keys(array_keys($question['criteria']), .0025);
                    $probabilities[$category] = .99;
                    $answers[$key] = ['type' => 'choice', 'choice' => $category, 'confidence' => .99, 'probabilities' => $probabilities];
                }
            }

            return Http::response(['id' => 'decision', 'model' => 'jev-test', 'answers' => $answers]);
        });
    }

    private function draft(string $text, string $hash = 'source'): AiResponse
    {
        return new AiResponse('', 'fake', 'test', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => [
            'completeness' => 'complete', 'claims' => [['text' => $text, 'document_id' => 1, 'evidence_hash' => $hash]],
            'limitations' => [], 'render_table' => false, 'requires_selection' => false,
        ]]]);
    }

    private function answer(?string $durableRunId = null): \App\Agent\AgentAnswer
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument(['document_id' => 1, 'title' => 'CASE-A', 'evidence' => [[
            'chunk_id' => 1, 'evidence_hash' => 'source', 'content' => 'CASE-A: the merchant accepted the refund request.', 'complete_source' => true,
        ]]]);
        $focus = ['topic' => 'case', 'identifiers' => ['CASE-A'], 'aspect' => 'resolution', 'fields' => ['*']];

        return app(AgentAnswerSynthesizer::class)->synthesize('What did the merchant confirm for CASE-A?',
            new AgentExecutionContext('test-run', 'default', 'demo', 'chat', 'user', '1', 'en', 'UTC'),
            new AgentLoopOutcome('answer', $evidence, []), understanding: ['focus' => $focus, 'subquestions' => [$focus]], durableRunId: $durableRunId);
    }

    public function test_one_repair_replaces_an_unsupported_execution_claim_with_the_documented_confirmation(): void
    {
        $this->setupDecisions();
        config(['agent.synthesis.model' => 'vendor/primary', 'agent.synthesis.repair_model' => 'vendor/repair']);
        $ai = Mockery::mock(AiManager::class);
        $calls = 0;
        $ai->shouldReceive('chatWithHistory')->twice()->andReturnUsing(function ($system, $messages, $options) use (&$calls) {
            $this->assertSame($calls === 0 ? 'vendor/primary' : 'vendor/repair', $options['model']);
            return $calls++ === 0 ? $this->draft('CASE-A refund was paid.') : $this->draft('The merchant accepted the CASE-A refund request.');
        });
        $this->app->instance(AiManager::class, $ai);

        $answer = $this->answer();

        $this->assertSame('The merchant accepted the CASE-A refund request.', $answer->answer);
        $this->assertSame('accepted', $answer->grounding['repair']['status']);
        $this->assertTrue($answer->grounding['coverage_validation']['sufficient']);
        $this->assertSame('accepted', $answer->grounding['semantic_validation'][1]['checks'][0]['status']);
        Http::assertSentCount(5);
    }

    public function test_a_repair_cannot_bypass_the_original_source_hash_gate_or_trigger_a_second_repair(): void
    {
        $this->setupDecisions();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            $this->draft('CASE-A refund was paid.'), $this->draft('The merchant accepted the CASE-A refund request.', 'invented'));
        $this->app->instance(AiManager::class, $ai);

        $answer = $this->answer();

        $this->assertSame('insufficient', $answer->completeness);
        $this->assertStringNotContainsString('The merchant accepted', $answer->answer);
        $this->assertSame('unchanged', $answer->grounding['repair']['status']);
        $this->assertSame([], $answer->citations);
        Http::assertSentCount(2);
    }

    public function test_repaired_claims_cannot_move_a_source_to_another_independent_task(): void
    {
        $evidence = ['documents' => [['document_id' => 1, 'evidence' => [[
            'evidence_hash' => 'source-a', 'research_flow_ids' => [0],
        ]]], ['document_id' => 2, 'evidence' => [[
            'evidence_hash' => 'source-b', 'research_flow_ids' => [1],
        ]]]]];
        $claims = [['text' => 'Fact B', 'document_id' => 2, 'evidence_hash' => 'source-b', 'subquestion_id' => 0],
            ['text' => 'Unregistered source', 'document_id' => 1, 'evidence_hash' => 'invented', 'subquestion_id' => 0]];
        $result = (new \ReflectionMethod(AgentAnswerSynthesizer::class, 'bindRepairTasks'))->invoke(
            app(AgentAnswerSynthesizer::class), $claims, $evidence);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['subquestion_id']);
        $this->assertSame('Fact B', $result[0]['text']);
    }

    public function test_a_durable_run_verifies_the_single_corrected_draft_in_distinct_dispatch_slots(): void
    {
        $this->setupDecisions();
        $run = AgentRun::create(['run_id' => 'durable-repair', 'tenant_id' => 'default', 'project_key' => 'demo',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => '1', 'locale' => 'en', 'timezone' => 'UTC',
            'status' => 'running', 'input_json' => []]);
        $initial = $this->draft('The merchant accepted the CASE-A refund request.');
        $initial = new AiResponse('', 'fake', 'test', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => [
            ...$initial->toolCalls[0]['arguments'], 'claims' => [...$initial->toolCalls[0]['arguments']['claims'],
                ['text' => 'CASE-A refund was paid.', 'document_id' => 1, 'evidence_hash' => 'source']],
        ]]]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn($initial, $this->draft('The merchant accepted the CASE-A refund request.'));
        $this->app->instance(AiManager::class, $ai);
        $answer = $this->answer($run->run_id);

        $this->assertSame('accepted', $answer->grounding['repair']['status']);
        $this->assertSame('The merchant accepted the CASE-A refund request.', $answer->answer);
        $ledger = $run->fresh()->result_json;
        foreach (['focused_decision', 'focused_decision_repair', 'original_question_relevance_decision', 'original_question_relevance_decision_repair'] as $slot) {
            $this->assertSame('completed', $ledger[$slot]['status']);
        }
        $this->assertNotSame($ledger['focused_decision']['signature'], $ledger['focused_decision_repair']['signature']);
        $this->expectException(\RuntimeException::class);
        app(\App\Agent\Grounding\DecisionOnce::class)->run($run->run_id, 'third-draft', fn () => throw new \LogicException('Must never dispatch'), 'focused_decision_repair');
    }
}
