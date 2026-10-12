<?php

declare(strict_types=1);

namespace Tests\Unit\Decisions;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Grounding\FocusedClaimEvaluator;
use App\Agent\Grounding\OriginalQuestionRelevanceEvaluator;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OriginalQuestionRelevanceEvaluatorTest extends TestCase
{
    private function decisions(float $relevance): void
    {
        config(['reasoning.selective_validation' => true, 'reasoning.threshold' => .8, 'ai.providers.openrouter.key' => 'test']);
        Http::fake(function ($request) use ($relevance) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                $answers[$key] = $question['type'] === 'noul' ? ['type' => 'noul', 'noul' => $relevance]
                    : ['type' => 'choice', 'choice' => 'supported_relevant', 'confidence' => .96,
                        'probabilities' => ['supported_relevant' => .96, 'supported_off_topic' => .01,
                            'unsupported' => .01, 'conflicting' => .01, 'insufficient_context' => .01]];
            }
            return Http::response(['id' => 'decision', 'model' => 'jev-test', 'answers' => $answers]);
        });
    }

    #[DataProvider('offTopicDebugTurns')]
    public function test_source_supported_claim_is_vetoed_against_the_original_question(string $question, string $claim, array $understanding): void
    {
        $this->decisions(.03);
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument(['document_id' => 226, 'title' => 'Source', 'project_key' => 'demo',
            'evidence' => [['chunk_id' => 390, 'content' => $claim, 'evidence_hash' => 'original']]]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse('', 'fake', 'test', toolCalls: [[
            'name' => 'submit_agent_answer', 'arguments' => ['claims' => [[
                'text' => $claim, 'document_id' => 226, 'evidence_hash' => 'original', 'subquestion_id' => 0,
            ]], 'completeness' => 'complete', 'limitations' => [], 'render_table' => false, 'requires_selection' => false],
        ]]));
        $this->app->instance(AiManager::class, $ai);
        $answer = app(AgentAnswerSynthesizer::class)->synthesize($question,
            new AgentExecutionContext('run', 'default', 'demo', 'chat', 'user', '1', 'it', 'UTC'),
            new AgentLoopOutcome('answer', $evidence, []), understanding: $understanding);
        $this->assertSame('insufficient', $answer->completeness);
        $this->assertSame('off_topic_original_question', $answer->grounding['reason']);
        $this->assertSame([], $answer->citations);
        $this->assertStringNotContainsString($claim, $answer->answer);
        $this->assertSame('rejected', $answer->grounding['semantic_validation'][0]['checks'][0]['status']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => ($r['state']['original_question'] ?? null) === $question
            && $r['questions']['relevance_0']['type'] === 'noul');
    }

    public static function offTopicDebugTurns(): array
    {
        $wrongTask = ['topic' => 'opzioni', 'identifiers' => [], 'aspect' => 'servizi', 'fields' => ['*'],
            'question' => 'Quali sono le opzioni disponibili per la logistica e spedizioni?', 'kb_queries' => ['Servizi di spedizione']];
        return [
            'assistant access confused with portal credentials' => ['Hai accesso ai documenti?',
                'Valentina Rinaldi ha chiesto informazioni per accedere al portale GestiClaim per aprire e seguire i reclami.',
                ['available' => false, 'language' => 'it', 'action' => 'research', 'focus' => [], 'subquestions' => []]],
            'explicit person dropped by contextual interpretation' => ['COsa possiamo fare con Rinaldi',
                'Rotta Sicura Logistics offre tre livelli di servizio, differenziati per tempo di resa e prezzo.',
                ['available' => true, 'language' => 'it', 'action' => 'research', 'focus' => array_intersect_key($wrongTask,
                    array_flip(['topic', 'identifiers', 'aspect', 'fields'])), 'subquestions' => [$wrongTask]]],
            'customer assertion substituted for merchant confirmation' => ['Did the merchant confirm that ITEM-B is ready?',
                'The customer says ITEM-B is ready.', ['available' => true, 'action' => 'research',
                    'focus' => ['topic' => 'item', 'identifiers' => ['ITEM-B'], 'aspect' => 'merchant_confirmation', 'fields' => []],
                    'subquestions' => []]],
        ];
    }

    public function test_relevance_veto_cannot_be_bypassed_by_exact_record_field_fallback(): void
    {
        $this->decisions(.02);
        $evidence = ['documents' => [], 'api_tools' => [['execution_id' => 9, 'evidence_hash' => 'h',
            'result' => ['records' => [['id' => 'ORDER-A', 'status' => 'delivered']]]]]];
        $focus = ['topic' => 'order', 'identifiers' => ['ORDER-A'], 'aspect' => 'status', 'fields' => ['status']];
        // Force uncertain support, which ordinarily produces an exact-record fallback.
        Http::fake(function ($request) {
            $answers = [];
            foreach ($request['questions'] as $key => $q) {
                $answers[$key] = $q['type'] === 'noul' ? ['type' => 'noul', 'noul' => .02]
                    : ['type' => 'choice', 'choice' => 'supported_relevant', 'confidence' => .28,
                        'probabilities' => ['supported_relevant' => .28, 'supported_off_topic' => .18,
                            'unsupported' => .18, 'conflicting' => .18, 'insufficient_context' => .18]];
            }
            return Http::response(['id' => 'decision', 'model' => 'jev-test', 'answers' => $answers]);
        });
        $result = app(FocusedClaimEvaluator::class)->evaluate('Who placed ORDER-A?', $evidence,
            [['text' => 'ORDER-A is delivered.', 'tool_execution_id' => 9, 'evidence_hash' => 'h']],
            ['focus' => $focus, 'subquestions' => [$focus]], 'demo', 'default');
        $this->assertFalse($result['valid']);
        $this->assertSame([], $result['claims']);
        $this->assertSame('off_topic_original_question', $result['reason']);
        $this->assertSame('unverified', $result['subquestions'][0]['status']);
        Http::assertSentCount(2);
    }

    #[DataProvider('uncertainRelevance')]
    public function test_uncertain_malformed_or_unavailable_relevance_fails_closed(mixed $reply, int $status): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::fake(['*' => Http::response($reply, $status)]);
        $result = app(OriginalQuestionRelevanceEvaluator::class)->apply('Document access?',
            ['valid' => true, 'claims' => [['text' => 'A person has portal access.']], 'semantic_validation' => []]);
        $this->assertFalse($result['valid']);
        $this->assertSame([], $result['claims']);
        $this->assertSame('original_question_unverified', $result['reason']);
    }

    public static function uncertainRelevance(): array
    {
        return [
            [['id' => 'x', 'model' => 'jev-test', 'answers' => ['relevance_0' => ['type' => 'noul', 'noul' => .5]]], 200],
            [['answers' => []], 200], [['error' => 'Unavailable'], 503],
        ];
    }

    public function test_relevance_can_never_override_a_failed_source_validation(): void
    {
        Http::preventStrayRequests();
        $grounding = ['valid' => false, 'reason' => 'source_hash_mismatch', 'claims' => [['text' => 'Unverified data']]];
        $this->assertSame($grounding, app(OriginalQuestionRelevanceEvaluator::class)->apply('Data?', $grounding));
        Http::assertNothingSent();
    }

    public function test_old_retrieval_plans_do_not_exhaust_the_budget_or_remove_authorized_source_context(): void
    {
        $this->decisions(.99);
        $source = str_repeat('RECORD-A: the merchant has promised shipment. ', 350);
        $references = [['document_id' => 9, 'identifiers' => ['RECORD-A']]];
        $result = app(OriginalQuestionRelevanceEvaluator::class)->apply('What did the merchant promise for RECORD-A?',
            ['valid' => true, 'claims' => [['text' => 'Shipment is promised.', 'quote' => $source]], 'semantic_validation' => []],
            navigation: ['references' => $references, 'dialogue' => [
                ['role' => 'user', 'text' => 'Tell me about RECORD-A', 'interpreted_goal' => 'Find RECORD-A',
                    'questions' => [['kb_queries' => [str_repeat('Old query ', 2500)]]]],
                ['role' => 'assistant', 'action' => 'research', 'answered_questions' => [['kb_queries' => [str_repeat('Old query ', 2500)]]]],
            ]]);
        $this->assertTrue($result['valid']);
        $this->assertLessThan(32768, $result['semantic_validation'][0]['state_bytes']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($source, $references) {
            $state = $request['state'];
            $this->assertSame([$source], array_values($state['sources']));
            $this->assertSame($references, $state['conversation_for_reference_resolution_only']['references']);
            $this->assertSame('Find RECORD-A', $state['conversation_for_reference_resolution_only']['dialogue'][0]['interpreted_goal']);
            $this->assertArrayNotHasKey('questions', $state['conversation_for_reference_resolution_only']['dialogue'][0]);
            $this->assertArrayNotHasKey('answered_questions', $state['conversation_for_reference_resolution_only']['dialogue'][1]);
            return true;
        });
    }

    public function test_a_partial_case_event_keeps_its_verified_subject_context_without_requiring_the_whole_story(): void
    {
        $this->decisions(.98);
        $source = 'Ada Rossi requested a refund. The merchant confirmed cancellation and promised repayment in three days.';
        $result = app(OriginalQuestionRelevanceEvaluator::class)->apply('Tell me everything that happened to Ada Rossi',
            ['valid' => true, 'claims' => [['text' => 'Her order was cancelled.', 'quote' => $source]], 'semantic_validation' => []]);

        $this->assertTrue($result['valid']);
        $this->assertSame('Her order was cancelled.', $result['claims'][0]['text']);
        Http::assertSent(fn ($request) => $request['state']['sources'][$request['state']['candidates'][0]['source_ref']] === $source
            && str_contains($request['questions']['relevance_0']['instructions'], 'NOT answer completeness')
            && str_contains($request['questions']['relevance_0']['criteria']['true'], 'need not answer the entire question'));
    }

    public function test_bounded_negative_claim_carries_server_verified_source_completeness(): void
    {
        $this->decisions(.98);
        $result = app(OriginalQuestionRelevanceEvaluator::class)->apply('Did the merchant confirm receipt?',
            ['valid' => true, 'claims' => [['text' => 'The complete ticket does not confirm receipt.',
                'quote' => 'The customer requested receipt confirmation.', 'source_title' => 'Order case', 'complete_source' => true]],
                'semantic_validation' => []]);
        $this->assertTrue($result['valid']);
        Http::assertSent(fn ($request) => $request['state']['candidates'][0]['complete_source'] === true
            && $request['state']['candidates'][0]['source_title'] === 'Order case');
    }

    public function test_claims_share_subject_context_instead_of_repeating_a_long_source(): void
    {
        $this->decisions(.98);
        $source = str_repeat('Shared verified case context. ', 600);
        $result = app(OriginalQuestionRelevanceEvaluator::class)->apply('Describe the case',
            ['valid' => true, 'claims' => [['text' => 'First event.', 'quote' => $source],
                ['text' => 'Second event.', 'quote' => $source]], 'semantic_validation' => []]);
        $this->assertTrue($result['valid']);
        $this->assertCount(2, $result['claims']);
        Http::assertSent(fn ($request) => count($request['state']['sources']) === 1
            && $request['state']['candidates'][0]['source_ref'] === $request['state']['candidates'][1]['source_ref']);
    }

    public function test_only_relevant_claims_survive_a_mixed_answer(): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::fake(['*' => Http::response(['id' => 'x', 'model' => 'jev-test', 'answers' => [
            'relevance_0' => ['type' => 'noul', 'noul' => .98], 'relevance_1' => ['type' => 'noul', 'noul' => .02],
        ]])]);
        $result = app(OriginalQuestionRelevanceEvaluator::class)->apply('What is ORDER-A status?',
            ['valid' => true, 'claims' => [['text' => 'ORDER-A is delivered.', 'subquestion_id' => 0],
                ['text' => 'The company offers premium shipping.', 'subquestion_id' => 0]], 'semantic_validation' => []]);
        $this->assertTrue($result['valid']);
        $this->assertSame(['ORDER-A is delivered.'], array_column($result['claims'], 'text'));
        $this->assertTrue($result['partial']);
    }
}
