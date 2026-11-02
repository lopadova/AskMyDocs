<?php

namespace Tests\Unit\Agent;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class VerifiedRepairAugmentationTest extends TestCase
{
    public function test_a_lower_scoring_repair_can_restore_a_verified_missing_comparison_side_without_erasing_the_first(): void
    {
        config(['reasoning.selective_validation' => true, 'reasoning.selective_repair' => true,
            'agent.presentation.enabled' => false, 'ai.providers.openrouter.key' => 'test']);
        Http::fake(function ($request) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                if ($key === 'covered') {
                    $text = implode(' ', $request['state']['answer']);
                    $p = str_contains($text, 'expected') ? (str_contains($text, 'received') ? .95 : .7) : .3;
                    $answers[$key] = ['type' => 'noul', 'noul' => $p];
                } elseif ($question['type'] === 'noul') $answers[$key] = ['type' => 'noul', 'noul' => .99];
                else {
                    $p = array_fill_keys(array_keys($question['criteria']), .0025);
                    $p['supported_relevant'] = .99;
                    $answers[$key] = ['type' => 'choice', 'choice' => 'supported_relevant', 'confidence' => .99, 'probabilities' => $p];
                }
            }
            return Http::response(['id' => 'decision', 'model' => 'test', 'answers' => $answers]);
        });
        $before = 'CASE-A expected ITEM-A.';
        $addition = 'CASE-A actually received ITEM-B.';
        $envelope = app(AgentEvidenceFactory::class)->empty();
        foreach ([1 => $before, 2 => $addition] as $id => $text) $envelope->addDocument(['document_id' => $id, 'title' => 'CASE-A',
            'evidence' => [['chunk_id' => $id, 'evidence_hash' => 'source-'.$id, 'content' => $text, 'complete_source' => true]]]);
        $draft = fn ($text, $id) => new AiResponse('', 'fake', 'test', toolCalls: [[
            'name' => 'submit_agent_answer', 'arguments' => ['claims' => [['text' => $text, 'document_id' => $id, 'evidence_hash' => 'source-'.$id]],
                'completeness' => 'complete', 'limitations' => [], 'requires_selection' => false, 'render_table' => false],
        ]]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn($draft($before, 1), $draft($addition, 2));
        $this->app->instance(AiManager::class, $ai);
        $focus = ['topic' => 'case', 'identifiers' => ['CASE-A'], 'aspect' => 'comparison', 'fields' => ['*']];
        $answer = app(AgentAnswerSynthesizer::class)->synthesize('Compare expected and actual items for CASE-A.',
            new AgentExecutionContext('test', 'default', 'demo', 'chat', 'user', '1', 'en', 'UTC'),
            new AgentLoopOutcome('answer', $envelope, []), understanding: ['focus' => $focus, 'subquestions' => [$focus]]);
        $this->assertStringContainsString($before, $answer->answer);
        $this->assertStringContainsString($addition, $answer->answer);
        $this->assertSame('augmented', $answer->grounding['repair']['status']);
        $this->assertSame(1, $answer->grounding['repair']['added_fact_count']);
        $this->assertTrue($answer->grounding['coverage_validation']['sufficient']);
        $this->assertCount(2, $answer->grounding['claims']);
    }
}
