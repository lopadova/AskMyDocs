<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AgentAnswerCoverageNoticeTest extends TestCase
{
    public static function coverage(): array
    {
        return ['requested facts covered' => [.99, false], 'requested time missing' => [.02, true], 'coverage service unavailable' => [null, true]];
    }

    #[DataProvider('coverage')]
    public function test_excluded_extra_claims_do_not_imply_that_a_fully_answered_request_is_incomplete(?float $coverage, bool $notice): void
    {
        config(['reasoning.selective_validation' => true, 'reasoning.selective_repair' => true,
            'reasoning.threshold' => .8, 'agent.presentation.enabled' => false, 'ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($coverage) {
            if (isset($request['questions']['covered']) && $coverage === null) {
                return Http::response([], 503);
            }
            $answers = [];
            foreach ($request['questions'] as $key => $q) {
                if ($q['type'] === 'noul') {
                    $answers[$key] = ['type' => 'noul', 'noul' => $key === 'covered' ? $coverage : .99];
                } else {
                    $category = $key === 'claim_2' ? 'unsupported' : 'supported_relevant';
                    $probabilities = array_fill_keys(array_keys($q['criteria']), .0025);
                    $probabilities[$category] = .99;
                    $answers[$key] = ['type' => 'choice', 'choice' => $category, 'probabilities' => $probabilities, 'confidence' => .99];
                }
            }
            return Http::response(['id' => 'check', 'model' => 'jev-test', 'answers' => $answers]);
        });
        $source = 'CASE-A: cambio predisposto senza sovrapprezzi, tempo previsto 5–8 giorni. Nessuna consegna effettuata.';
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument(['document_id' => 7, 'title' => 'CASE-A', 'source_path' => 'case-a.md', 'origin' => 'primary',
            'evidence' => [['content' => $source, 'evidence_hash' => 'hash']]]);
        $claims = array_map(fn ($text) => ['text' => $text, 'quote' => $source, 'document_id' => 7,
            'tool_execution_id' => null, 'evidence_hash' => 'hash', 'subquestion_id' => 0], [
            'Il cambio per CASE-A è stato predisposto.', 'Non prevede sovrapprezzi.', 'Il pacco è già stato consegnato.',
        ]);
        $response = new AiResponse('', 'fake', 'test', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => [
            'claims' => $claims, 'completeness' => 'complete', 'limitations' => [], 'requires_selection' => false, 'render_table' => false,
        ]]]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn($response);
        $this->app->instance(AiManager::class, $ai);
        $focus = ['topic' => 'case', 'identifiers' => ['CASE-A'], 'aspect' => 'conditions', 'fields' => ['*']];
        $question = $coverage === .99 ? 'Il cambio CASE-A è predisposto e ha costi?' : 'Il cambio CASE-A è predisposto, ha costi e quanto dura?';
        $answer = app(AgentAnswerSynthesizer::class)->synthesize($question,
            new AgentExecutionContext('test', 'acme', 'demo', 'chat', 'user', '1', 'it', 'Europe/Rome'),
            new AgentLoopOutcome('answer', $evidence, []), understanding: ['action' => 'research', 'focus' => $focus, 'subquestions' => [$focus]]);
        $this->assertSame($notice, str_contains($answer->answer, 'Potrebbero esserci aspetti della richiesta ancora da verificare'));
        $this->assertStringNotContainsString('gli altri dettagli richiesti non sono stati verificati', $answer->answer);
        $this->assertStringContainsString('Non prevede sovrapprezzi.', $answer->answer);
        $this->assertStringNotContainsString('già stato consegnato', $answer->answer);
        $this->assertCount(2, $answer->grounding['claims']);
        $this->assertSame([7], array_column($answer->citations, 'document_id'));
    }
}
