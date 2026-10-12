<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Chat\ChatQuestionPreprocessor;
use App\Services\Chat\QuestionAction;
use App\Services\Chat\Reasoning\QuestionOperationVerifier;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class QuestionOperationVerifierTest extends TestCase
{
    private function setupRoute(?float $probability): void
    {
        config(['reasoning.selective_validation' => true, 'reasoning.threshold' => .8, 'ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
        Http::fake(fn () => $probability === null ? Http::response([], 503)
            : Http::response(['model' => 'jev-test', 'answers' => ['matches' => ['type' => 'noul', 'noul' => $probability]]]));
    }

    private function interpretation(bool $provenance): AiResponse
    {
        $focus = ['topic' => 'ticket', 'identifiers' => ['SKU-A'],
            'aspect' => $provenance ? 'source_provenance' : 'price', 'fields' => $provenance ? [] : ['price']];
        $queries = $provenance ? [] : ['SKU-A prices'];
        return new AiResponse(json_encode(['language' => 'it', 'intent' => 'Prezzi SKU-A',
            'action' => $provenance ? 'provenance' : 'research', 'kb_queries' => $queries,
            'mentions' => [['text' => 'SKU-A', 'type' => 'identifier']], 'references_previous_turn' => true,
            'context_mode' => 'conversation', 'requires_research' => ! $provenance,
            'transition' => $provenance ? 'provenance' : 'continue', 'focus' => $focus,
            'subquestions' => [$focus + ['question' => $provenance ? 'Quali fonti?' : 'Quali sono i prezzi di SKU-A?',
                'kb_queries' => $queries, 'context_mode' => 'conversation', 'requires_research' => ! $provenance]],
            'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '',
        ], JSON_THROW_ON_ERROR), 'fake', 'test');
    }

    public function test_an_unrequested_shortcut_is_reinterpreted_once_instead_of_bypassing_factual_validation(): void
    {
        $this->setupRoute(.03);
        $ai = Mockery::mock(AiManager::class);
        $calls = 0;
        $ai->shouldReceive('chatWithProvider')->twice()->andReturnUsing(function ($provider, $system, $messages) use (&$calls) {
            if ($calls === 1) {
                $this->assertSame('invalid_shortcut_operation', json_decode($messages[0]['content'], true)['validation_error']);
            }
            return $this->interpretation($calls++ === 0);
        });
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Quali sono i prezzi nel ticket SKU-A?', reasoningContext: [
            'known_identifiers' => ['SKU-A'], 'dialogue' => [['role' => 'assistant', 'offers' => ['show_sources']]],
        ]);
        $this->assertTrue($result->available);
        $this->assertSame(QuestionAction::Research, $result->effectiveAction());
        $this->assertTrue($result->needsResearch());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['state']['current_message'] === 'Quali sono i prezzi nel ticket SKU-A?');
    }

    public function test_an_actual_source_attribution_request_can_use_the_shortcut(): void
    {
        $this->setupRoute(.99);
        $this->assertTrue(app(QuestionOperationVerifier::class)->matches('Quali fonti hai usato?', QuestionAction::Provenance, [], []));
    }

    public function test_an_unavailable_route_verifier_does_not_trigger_a_second_generation_call(): void
    {
        $this->setupRoute(null);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->interpretation(true));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Quali sono i prezzi nel ticket SKU-A?', reasoningContext: []);
        $this->assertFalse($result->available);
        $this->assertSame('provider_unavailable', $result->failureReason);
    }
}
