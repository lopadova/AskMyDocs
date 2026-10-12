<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentAnswerPresenter;
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

final class AgentAnswerPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.presentation.enabled' => true, 'agent.synthesis.model' => 'openai/gpt-6-luna',
            'reasoning.threshold' => .8, 'ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
    }

    private function decisions(float $faithful = .99, float $complete = .99): void
    {
        Http::fake(function ($request) use ($faithful, $complete) {
            $answers = [];
            foreach (array_keys($request['questions']) as $key) {
                $answers[$key] = ['type' => 'noul', 'noul' => str_starts_with($key, 'faithful_') ? $faithful : $complete];
            }
            return Http::response(['id' => 'presentation-check', 'model' => 'jev-test', 'answers' => $answers]);
        });
    }

    private function context(): AgentExecutionContext
    {
        return new AgentExecutionContext('presentation-test', 'acme', 'demo', 'chat', 'user', '1', 'it', 'Europe/Rome');
    }

    private function claims(): array
    {
        return [
            ['text' => 'Per il caso CASE-A, Nencini ha predisposto il cambio.', 'subquestion_id' => 0,
                'document_id' => 7, 'evidence_hash' => 'hash', 'quote' => 'CASE-A: cambio predisposto senza sovrapprezzi.', 'entity_identifiers' => ['CASE-A']],
            ['text' => 'Per il caso CASE-A, Nencini ha confermato che il cambio non ha sovrapprezzi.', 'subquestion_id' => 0,
                'document_id' => 7, 'evidence_hash' => 'hash', 'quote' => 'CASE-A: cambio predisposto senza sovrapprezzi.'],
        ];
    }

    private function response(array $paragraphs): AiResponse
    {
        return new AiResponse('', 'fake', 'openai/gpt-6-luna', toolCalls: [[
            'name' => 'present_verified_answer', 'arguments' => ['paragraphs' => $paragraphs],
        ]]);
    }

    public function test_a_verified_paragraph_merges_repeated_attribution_without_changing_original_facts(): void
    {
        $this->decisions();
        $original = $this->claims();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(function ($system, $history, $options) use ($original) {
            $data = json_decode($history[0]['content'], true);
            $this->assertSame(array_column($original, 'text'), array_column($data['verified_facts'], 'text'));
            $this->assertArrayNotHasKey('sources', $data); // The rewrite cannot borrow previously rejected source facts.
            $this->assertSame('openai/gpt-6-luna', $options['model']);
            return true;
        })->andReturn($this->response([['text' => 'Per **CASE-A**, il cambio è stato predisposto senza sovrapprezzi.', 'claim_ids' => [0, 1]]]));

        $result = (new AgentAnswerPresenter($ai))->present('Come procede CASE-A?', $this->context(), $original);
        $this->assertSame('accepted', $result['trace']['status']);
        $this->assertCount(1, $result['paragraphs']);
        $this->assertSame($this->claims(), $original);
        Http::assertSentCount(1);
    }

    public static function invalidParagraphs(): array
    {
        return [
            'unknown fact' => [[['text' => 'CASE-A', 'claim_ids' => [0, 2]]], false],
            'omitted fact' => [[['text' => 'CASE-A', 'claim_ids' => [0]]], false],
            'repeated fact' => [[['text' => 'CASE-A', 'claim_ids' => [0, 1]], ['text' => 'Again', 'claim_ids' => [0]]], false],
            'changed identifier' => [[['text' => 'CASE-B: cambio gratuito.', 'claim_ids' => [0, 1]]], false],
            'mixed topics' => [[['text' => 'CASE-A', 'claim_ids' => [0, 1]]], true],
            'reversed topics' => [[['text' => 'Second topic', 'claim_ids' => [1]], ['text' => 'CASE-A', 'claim_ids' => [0]]], true],
            'active markup' => [[['text' => '<script>CASE-A</script>', 'claim_ids' => [0, 1]]], false],
            'embedded media' => [[['text' => 'CASE-A ![image](https://example.test/image)', 'claim_ids' => [0, 1]]], false],
            'active link' => [[['text' => '[CASE-A](javascript:alert(1))', 'claim_ids' => [0, 1]]], false],
        ];
    }

    #[DataProvider('invalidParagraphs')]
    public function test_invalid_references_or_scope_are_rejected_before_semantic_approval(array $paragraphs, bool $twoTopics): void
    {
        $claims = $this->claims();
        if ($twoTopics) {
            $claims[1]['subquestion_id'] = 1;
        }
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->response($paragraphs));
        $result = (new AgentAnswerPresenter($ai))->present('CASE-A', $this->context(), $claims);
        $this->assertNull($result['paragraphs']);
        Http::assertNothingSent();
    }

    public static function failedVerification(): array
    {
        return ['unsupported addition' => [.02, .99], 'lost condition' => [.99, .03], 'uncertain approval' => [.65, .99]];
    }

    #[DataProvider('failedVerification')]
    public function test_unfaithful_incomplete_or_uncertain_rewrites_are_not_shown(float $faithful, float $complete): void
    {
        $this->decisions($faithful, $complete);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->response([['text' => 'CASE-A: cambio gratuito e già consegnato.', 'claim_ids' => [0, 1]]]));
        $result = (new AgentAnswerPresenter($ai))->present('CASE-A', $this->context(), $this->claims());
        $this->assertNull($result['paragraphs']);
        $this->assertSame('rejected', $result['trace']['status']);
        Http::assertSentCount(1);
    }

    public function test_a_generation_failure_keeps_the_safe_fallback_without_a_retry(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andThrow(new \RuntimeException('Provider unavailable'));
        $result = (new AgentAnswerPresenter($ai))->present('CASE-A', $this->context(), $this->claims());
        $this->assertNull($result['paragraphs']);
        $this->assertSame('unavailable', $result['trace']['status']);
        Http::assertNothingSent();
    }

    public function test_every_block_is_checked_and_a_failed_second_batch_rejects_the_whole_rewrite(): void
    {
        $claims = $paragraphs = [];
        for ($i = 0; $i < 6; $i++) {
            $claims[] = ['text' => 'Verified fact '.$i, 'subquestion_id' => 0];
            $paragraphs[] = ['text' => 'Display block '.$i, 'claim_ids' => [$i]];
        }
        Http::fake(function ($request) {
            $blocks = $request['state']['blocks'];
            $this->assertLessThanOrEqual(10, count($request['questions']));
            $answers = [];
            foreach (array_keys($request['questions']) as $key) {
                $answers[$key] = ['type' => 'noul', 'noul' => count($blocks) === 1 ? .6 : .99];
            }
            foreach ($blocks as $block) {
                $index = substr($block['candidate_text'], -1);
                $this->assertSame(['Verified fact '.$index], $block['verified_facts']);
            }
            return Http::response(['model' => 'jev-test', 'answers' => $answers]);
        });
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->response($paragraphs));
        $result = (new AgentAnswerPresenter($ai))->present('Explain', $this->context(), $claims);
        $this->assertSame('rejected', $result['trace']['status']);
        $this->assertNull($result['paragraphs']);
        $this->assertCount(2, $result['trace']['verification']['batches']);
        Http::assertSentCount(2);
    }

    public function test_reference_context_keeps_only_the_same_case_and_subjects_attested_in_its_sources(): void
    {
        $this->decisions();
        $claims = [
            ['text' => 'CASE-A: rimborso promesso.', 'quote' => 'Gianni Rossi CASE-A', 'subquestion_id' => 0],
            ['text' => 'CASE-B: spedizione annunciata.', 'quote' => 'Mauro Verdi CASE-B', 'subquestion_id' => 1],
        ];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->response([
            ['text' => 'Per Gianni Rossi è promesso un rimborso.', 'claim_ids' => [0]],
            ['text' => 'Per Mauro Verdi è annunciata una spedizione.', 'claim_ids' => [1]],
        ]));
        $result = (new AgentAnswerPresenter($ai))->present('Confronta', $this->context(), $claims, understanding: [
            'mentions' => ['Gianni Rossi', 'Mauro Verdi'], 'subquestions' => [
                ['identifiers' => ['CASE-A', 'Gianni Rossi', 'Unattested label']],
                ['identifiers' => ['CASE-B', 'Mauro Verdi']],
            ],
        ]);
        $this->assertSame('accepted', $result['trace']['status']);
        Http::assertSent(function ($request) {
            $contexts = $request['state']['case_reference_contexts'];
            $this->assertSame(['CASE-A', 'Gianni Rossi'], $contexts[0]['subject_labels_attested_in_bound_sources']);
            $this->assertNotContains('Mauro Verdi', $contexts[0]['reference_labels_attested_in_bound_sources']);
            $this->assertSame(['CASE-A: rimborso promesso.'], $contexts[0]['verified_facts_for_reference_resolution_only']);
            $this->assertArrayNotHasKey('candidate_passages_for_reference_resolution_only', $contexts[0]);
            return true;
        });
    }

    public function test_related_aspects_share_a_paragraph_and_only_attested_names_resolve_abbreviations(): void
    {
        $this->decisions();
        $claims = $this->claims();
        $claims[1]['subquestion_id'] = 1;
        foreach ($claims as &$claim) {
            $claim['quote'] .= ' Gianni Rossi.';
        }
        unset($claim);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(function ($system, $history) {
            $input = json_decode($history[0]['content'], true);
            $this->assertContains('Gianni Rossi', $input['reference_labels_attested_in_original_sources']);
            $this->assertNotContains('Unverified Person', $input['reference_labels_attested_in_original_sources']);
            $this->assertSame(1, count(array_unique(array_column($input['verified_facts'], 'presentation_group'))));
            return true;
        })->andReturn($this->response([['text' => 'Per **CASE-A**, il cambio è predisposto senza sovrapprezzi.', 'claim_ids' => [0, 1]]]));
        $result = (new AgentAnswerPresenter($ai))->present('Gianni Rossi', $this->context(), $claims, understanding: [
            'mentions' => ['Gianni Rossi', 'Unverified Person'], 'subquestions' => [
                ['identifiers' => ['CASE-A'], 'aspect' => 'status'], ['identifiers' => ['CASE-A'], 'aspect' => 'price'],
            ],
        ]);
        $this->assertSame('accepted', $result['trace']['status']);
        $this->assertSame([0, 1], $result['paragraphs'][0]['subquestion_ids']);
    }

    public function test_the_synthesizer_displays_the_rewrite_but_cites_and_remembers_the_original_claims(): void
    {
        config(['reasoning.selective_validation' => false, 'agent.grounding.batch.enabled' => false, 'agent.grounding.semantic.enabled' => false]);
        $this->decisions();
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $source = 'CASE-A: cambio predisposto senza sovrapprezzi.';
        $evidence->addDocument(['document_id' => 7, 'title' => 'CASE-A', 'source_path' => 'case-a.md', 'origin' => 'primary',
            'evidence' => [['content' => $source, 'evidence_hash' => 'hash']]]);
        $claims = $this->claims();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            new AiResponse('', 'fake', 'draft', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => [
                'claims' => $claims, 'completeness' => 'complete', 'limitations' => [], 'requires_selection' => false, 'render_table' => false,
            ]]]), $this->response([['text' => 'Per **CASE-A**, il cambio è stato predisposto senza sovrapprezzi.', 'claim_ids' => [0, 1]]]),
        );
        $this->app->instance(AiManager::class, $ai);
        $answer = app(AgentAnswerSynthesizer::class)->synthesize('Come procede CASE-A?', $this->context(), new AgentLoopOutcome('answer', $evidence, []));
        $this->assertSame('Per **CASE-A**, il cambio è stato predisposto senza sovrapprezzi.', $answer->answer);
        $this->assertSame(array_column($claims, 'text'), array_column($answer->grounding['claims'], 'text'));
        $this->assertSame([7], array_column($answer->citations, 'document_id'));
        $this->assertSame('accepted', $answer->grounding['presentation']['status']);
    }

    public function test_presented_aspects_of_one_subject_do_not_render_duplicate_topic_headings(): void
    {
        $text = 'Il cambio di CASE-A è gratuito; la consegna avviene al domicilio.';
        $paragraph = ['text' => $text, 'subquestion_id' => 0, 'subquestion_ids' => [0, 1], 'presentation_group' => 0, 'claim_ids' => [0, 1]];
        $u = ['subquestions' => [
            ['question' => 'Quanto costa CASE-A?', 'identifiers' => ['CASE-A']],
            ['question' => 'Dove arriva CASE-A?', 'identifiers' => ['CASE-A']],
        ]];
        $answer = (new \ReflectionMethod(AgentAnswerSynthesizer::class, 'composeResearch'))->invoke(app(AgentAnswerSynthesizer::class),
            $u, ['claims' => [$paragraph], 'coverage_validation' => ['sufficient' => true]], [], 'it', [$paragraph]);
        $this->assertSame($text, $answer);
        $this->assertStringNotContainsString('##', $answer);
    }
}
