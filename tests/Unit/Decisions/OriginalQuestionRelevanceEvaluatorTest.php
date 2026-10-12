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
