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

class AgentSourceExcerptSynthesisTest extends TestCase
{
    public function test_a_checked_source_excerpt_is_displayed_without_promoting_the_uncertain_business_claim(): void
    {
        config(['reasoning.selective_validation' => true, 'reasoning.selective_repair' => false,
            'reasoning.threshold' => .8, 'ai.providers.openrouter.key' => 'test', 'agent.presentation.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                if ($question['type'] === 'noul') {
                    $answers[$key] = ['type' => 'noul', 'noul' => .95];
                } else {
                    $probabilities = array_fill_keys(array_keys($question['criteria']), .0825);
                    $probabilities['supported_relevant'] = .67;
                    $answers[$key] = ['type' => 'choice', 'choice' => 'supported_relevant', 'confidence' => .67, 'probabilities' => $probabilities];
                }
            }
            return Http::response(['model' => 'jev-test', 'answers' => $answers]);
        });
        $source = "CASE-A\n\nCustomer: The address is wrong. Please change the shipping address.\n\nNo merchant reply is recorded.";
        $envelope = app(AgentEvidenceFactory::class)->empty();
        $envelope->import(['documents' => [['document_id' => 7, 'title' => 'CASE-A', 'evidence' => [[
            'content' => $source, 'evidence_hash' => 'h', 'complete_source' => true,
        ]]]]]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse('', 'fake', 'test', toolCalls: [[
            'name' => 'submit_agent_answer', 'arguments' => ['completeness' => 'complete', 'claims' => [[
                'text' => 'The merchant confirmed the change for CASE-A.', 'quote' => 'Please change the shipping address.',
                'document_id' => 7, 'evidence_hash' => 'h',
            ]], 'limitations' => [], 'render_table' => false, 'requires_selection' => false],
        ]]));
        $this->app->instance(AiManager::class, $ai);
        $sub = ['topic' => 'order', 'identifiers' => ['CASE-A'], 'fields' => ['problem', 'request'],
            'question' => 'What problem and change are reported for CASE-A?'];
        $answer = app(AgentAnswerSynthesizer::class)->synthesize($sub['question'],
            new AgentExecutionContext('excerpt-test', 'default', 'demo', 'chat', 'user', '1', 'it', 'Europe/Rome'),
            new AgentLoopOutcome('answer', $envelope, []), understanding: [
                'language' => 'it', 'action' => 'research', 'available' => true, 'focus' => $sub, 'subquestions' => [$sub],
            ]);
        $this->assertSame('partial', $answer->completeness);
        $this->assertSame([], $answer->grounding['claims']);
        $this->assertFalse($answer->grounding['source_excerpts'][0]['business_claim_approved']);
        $this->assertStringContainsString('The address is wrong.', $answer->answer);
        $this->assertStringNotContainsString('merchant confirmed', $answer->answer);
        $this->assertSame('source_excerpt', $answer->citations[0]['claims'][0]['kind']);
    }
}
