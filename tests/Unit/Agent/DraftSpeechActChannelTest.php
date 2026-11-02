<?php

namespace Tests\Unit\Agent;

use App\Agent\AgentAnswerPresenter;
use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Grounding\RequestedSpeechActEvaluator;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DraftSpeechActChannelTest extends TestCase
{
    private function decisions(float $requested = .98, float $nonfactual = .98): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::fake(function ($request) use ($requested, $nonfactual) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                $answers[$key] = $question['type'] === 'noul'
                    ? ['type' => 'noul', 'noul' => match ($key) {'requested' => $requested, 'nonfactual' => $nonfactual, default => .98}]
                    : ['type' => 'choice', 'choice' => 'supported_relevant', 'confidence' => .98,
                        'probabilities' => ['supported_relevant' => .98, 'supported_off_topic' => .005, 'unsupported' => .005, 'conflicting' => .005, 'insufficient_context' => .005]];
            }
            return Http::response(['id' => 'decision', 'model' => 'test', 'answers' => $answers]);
        });
    }

    public function test_the_synthesis_request_channel_reaches_display_without_becoming_a_source_claim(): void
    {
        $this->decisions();
        config(['reasoning.selective_validation' => true, 'reasoning.selective_repair' => false, 'agent.presentation.enabled' => true]);
        $question = 'Draft a note about ORDER-A and close by asking for a refund receipt if available.';
        $request = 'Could you confirm whether ORDER-A was refunded and provide a receipt if available?';
        $fact = 'Support authorized a refund for ORDER-A.';
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument(['document_id' => 11, 'project_key' => 'demo', 'title' => 'ORDER-A',
            'evidence' => [['chunk_id' => 12, 'content' => $fact, 'evidence_hash' => 'original']]]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->ordered()->andReturn(new AiResponse('', 'fake', 'test', toolCalls: [[
            'name' => 'submit_agent_answer', 'arguments' => ['completeness' => 'complete', 'claims' => [
                ['text' => $fact, 'document_id' => 11, 'evidence_hash' => 'original'],
            ], 'requested_speech_act' => $request, 'limitations' => [], 'render_table' => false, 'requires_selection' => false],
        ]]));
        // A failed optional factual rewrite cannot erase the separate request.
        $ai->shouldReceive('chatWithHistory')->once()->ordered()->andThrow(new \UnexpectedValueException('Invalid factual presentation.'));
        $this->app->instance(AiManager::class, $ai);
        $focus = ['topic' => 'order', 'identifiers' => ['ORDER-A'], 'aspect' => 'refund', 'fields' => []];
        $answer = app(AgentAnswerSynthesizer::class)->synthesize($question,
            new AgentExecutionContext('run', 'default', 'demo', 'chat', 'user', '1', 'en', 'UTC'),
            new AgentLoopOutcome('answer', $evidence, []), understanding: ['available' => true, 'requires_composition' => true,
                'focus' => $focus, 'subquestions' => [$focus], 'action' => 'research']);
        $this->assertStringContainsString($fact, $answer->answer);
        $this->assertStringContainsString($request, $answer->answer);
        $this->assertCount(1, $answer->grounding['claims']);
        $this->assertSame($fact, $answer->grounding['claims'][0]['text']);
        $this->assertSame('accepted_request_only', $answer->grounding['presentation']['status']);
    }

    #[DataProvider('repairOutcomes')]
    public function test_a_single_request_rewrite_still_needs_both_independent_proofs(bool $accepted): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        $original = 'After the refund, send its receipt.';
        $replacement = 'Could you confirm whether the refund occurred and share a receipt if available?';
        Http::fake(function ($request) use ($replacement, $accepted) {
            $repair = ($request['state']['proposed_nonfactual_request'] ?? '') === $replacement;
            return Http::response(['id' => 'decision', 'model' => 'test', 'answers' => [
                'requested' => ['type' => 'noul', 'noul' => $repair && ! $accepted ? .1 : .98],
                'nonfactual' => ['type' => 'noul', 'noul' => $repair ? .98 : .77],
            ]]);
        });
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->ordered()->andThrow(new \RuntimeException('Optional prose unavailable'));
        $ai->shouldReceive('chatWithHistory')->once()->ordered()->andReturn(new AiResponse('', 'fake', 'test', toolCalls: [[
            'name' => 'compose_draft_question', 'arguments' => ['text' => $replacement],
        ]]));
        $this->app->instance(AiManager::class, $ai);
        $fact = 'Support authorized a refund.';
        $result = app(AgentAnswerPresenter::class)->present('Draft a note asking whether the refund occurred, with a receipt if available.',
            new AgentExecutionContext('run', 'default', 'demo', 'chat', 'user', '1', 'en', 'UTC'),
            [['text' => $fact, 'subquestion_id' => 0]], understanding: ['requires_composition' => true], requestedSpeechAct: $original);
        $this->assertSame($accepted, $result['trace']['draft_request_repair']['accepted']);
        $this->assertSame($original, $result['trace']['draft_request']['candidate']);
        if ($accepted) {
            $this->assertSame([$fact, $replacement], array_column($result['paragraphs'], 'text'));
            $this->assertSame([], $result['paragraphs'][1]['claim_ids']);
        } else $this->assertNull($result['paragraphs']);
        Http::assertSentCount(2);
    }

    public static function repairOutcomes(): array { return [[true], [false]]; }

    #[DataProvider('rejectedRequests')]
    public function test_unasked_requests_or_assertions_never_enter_the_request_channel(float $requested, float $nonfactual): void
    {
        $this->decisions($requested, $nonfactual);
        $result = app(RequestedSpeechActEvaluator::class)->evaluate('Draft the requested note.',
            'The refund was completed and we guarantee delivery tomorrow.', [['text' => 'Support authorized a refund.']]);
        $this->assertFalse($result['accepted']);
    }

    public static function rejectedRequests(): array
    {
        return [[.02, .99], [.99, .02], [.6, .99], [.99, .6]];
    }

    public function test_provider_failure_never_approves_request_wording(): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $this->assertFalse(app(RequestedSpeechActEvaluator::class)->evaluate('Draft?', 'Could you reply?', [])['accepted']);
    }
}
