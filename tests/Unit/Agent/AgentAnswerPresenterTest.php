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

    private function ai(): \Mockery\MockInterface
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->byDefault()->andReturn($this->extraction([]));
        return $ai;
    }

    private function extraction(array $links): AiResponse
    {
        return new AiResponse('', 'fake', 'luna-parser', toolCalls: [[
            'name' => 'extract_answer_relations', 'arguments' => ['links' => $links],
        ]]);
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
        $ai = $this->ai();
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
        $ai = $this->ai();
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
        $ai = $this->ai();
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->response([['text' => 'CASE-A: cambio gratuito e già consegnato.', 'claim_ids' => [0, 1]]]));
        $result = (new AgentAnswerPresenter($ai))->present('CASE-A', $this->context(), $this->claims());
        $this->assertNull($result['paragraphs']);
        $this->assertSame('rejected', $result['trace']['status']);
        Http::assertSentCount(1);
    }

    public function test_a_generation_failure_keeps_the_safe_fallback_without_a_retry(): void
    {
        $ai = $this->ai();
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
        $ai = $this->ai();
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
        $ai = $this->ai();
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
            $this->assertSame(['Gianni Rossi'], $contexts[0]['reference_labels_visible_in_display_blocks']);
            $this->assertSame(['Mauro Verdi'], $contexts[1]['reference_labels_visible_in_display_blocks']);
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
        $ai = $this->ai();
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

    public function test_numeric_reference_labels_remain_strings_through_generation_and_verification(): void
    {
        $this->decisions();
        $identifier = '12345678901';
        $claims = [['text' => 'Per l’ordine '.$identifier.' il rimborso è promesso.',
            'quote' => 'Ordine '.$identifier.': rimborso promesso.', 'subquestion_id' => 0,
            'entity_identifiers' => [$identifier]]];
        $ai = $this->ai();
        $ai->shouldReceive('chatWithHistory')->once()->withArgs(function ($system, $history) use ($identifier) {
            $input = json_decode($history[0]['content'], true);
            $this->assertSame([$identifier], $input['reference_labels_attested_in_original_sources']);
            return true;
        })->andReturn($this->response([['text' => 'Per l’ordine **'.$identifier.'**, il rimborso è promesso.', 'claim_ids' => [0]]]));

        $result = (new AgentAnswerPresenter($ai))->present('Qual è l’esito?', $this->context(), $claims, understanding: [
            'mentions' => [$identifier], 'subquestions' => [['identifiers' => [$identifier]]],
        ]);
        $this->assertSame('accepted', $result['trace']['status']);
        Http::assertSent(function ($request) use ($identifier) {
            $case = $request['state']['case_reference_contexts'][0];
            $this->assertSame([$identifier], $case['reference_labels_attested_in_bound_sources']);
            $this->assertSame([$identifier], $case['subject_labels_attested_in_bound_sources']);
            return true;
        });
    }

    public function test_causal_support_uses_only_linked_facts_and_cannot_borrow_candidate_prose(): void
    {
        $claims = [['text' => 'CASE-A: pickup is unavailable; the system registered home delivery.',
            'quote' => 'CASE-A: pickup is unavailable; the system registered home delivery.', 'subquestion_id' => 0]];
        $ai = $this->ai();
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->response([
            ['text' => 'CASE-A: pickup is unavailable because the system registered home delivery.', 'claim_ids' => [0],
                'causal_links' => [['cause' => 'The system registered home delivery.', 'effect' => 'Pickup is unavailable.']]],
        ]));
        $ai->shouldReceive('chat')->once()->andReturn($this->extraction([[
            'paragraph_id' => 0, 'quote' => 'pickup is unavailable because the system registered home delivery.', 'kind' => 'causal',
            'from' => 'the system registered home delivery', 'to' => 'pickup is unavailable',
        ]]));
        Http::fake(function ($request) use ($claims) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                if ($question['type'] === 'choice') {
                    $state = $request['state'];
                    $this->assertArrayNotHasKey('blocks', $state);
                    $this->assertArrayNotHasKey('case_reference_contexts', $state);
                    $this->assertSame([array_column($claims, 'text')], array_values($state['approved_fact_sets']));
                    $answers[$key] = ['type' => 'choice', 'choice' => 'absent', 'confidence' => .94,
                        'probabilities' => ['explicit' => .03, 'absent' => .97, 'reversed' => 0]];
                } else {
                    $answers[$key] = ['type' => 'noul', 'noul' => .99];
                }
            }
            return Http::response(['model' => 'jev-test', 'answers' => $answers]);
        });
        $result = (new AgentAnswerPresenter($ai))->present('Explain CASE-A', $this->context(), $claims);
        $this->assertSame('rejected', $result['trace']['status']);
        $this->assertEquals(0, $result['trace']['verification']['answers']['faithful']['probability_true']);
        $this->assertNull($result['paragraphs']);
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidCausalSpans')]
    public function test_unbound_causal_spans_are_rejected_before_fact_verification(array $link): void
    {
        Http::preventStrayRequests();
        $ai = $this->ai();
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->response([
            ['text' => 'CASE-A: the exchange is free.', 'claim_ids' => [0, 1]],
        ]));
        $ai->shouldReceive('chat')->once()->andReturn($this->extraction([$link]));
        $result = (new AgentAnswerPresenter($ai))->present('CASE-A?', $this->context(), $this->claims());
        $this->assertSame('unavailable', $result['trace']['status']);
        $this->assertNull($result['paragraphs']);
        Http::assertNothingSent();
    }

    public static function invalidCausalSpans(): array
    {
        $link = ['paragraph_id' => 0, 'kind' => 'causal', 'quote' => 'CASE-A: the exchange is free.', 'from' => 'CASE-A', 'to' => 'the exchange is free'];
        return [
            'wrong paragraph' => [[...$link, 'paragraph_id' => 1]],
            'invented quote' => [[...$link, 'quote' => 'An unrelated causal statement.']],
            'invented cause' => [[...$link, 'from' => 'A different event']],
        ];
    }

    public function test_a_documented_causal_link_can_pass_its_independent_source_check(): void
    {
        Http::fake(function ($request) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                $answers[$key] = $question['type'] === 'choice'
                    ? ['type' => 'choice', 'choice' => 'explicit', 'confidence' => .98,
                        'probabilities' => ['explicit' => .99, 'absent' => .01, 'reversed' => 0]]
                    : ['type' => 'noul', 'noul' => .99];
            }
            return Http::response(['model' => 'jev-test', 'answers' => $answers]);
        });
        $ai = $this->ai();
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->response([
            ['text' => 'Shipment was released because payment cleared.', 'claim_ids' => [0],
                'causal_links' => [['cause' => 'Payment cleared.', 'effect' => 'Shipment was released.']]],
        ]));
        $ai->shouldReceive('chat')->once()->andReturn($this->extraction([[
            'paragraph_id' => 0, 'quote' => 'Shipment was released because payment cleared.', 'kind' => 'causal',
            'from' => 'payment cleared', 'to' => 'Shipment was released',
        ]]));
        $result = (new AgentAnswerPresenter($ai))->present('What happened?', $this->context(),
            [['text' => 'Shipment was released because payment cleared.', 'subquestion_id' => 0]]);
        $this->assertSame('accepted', $result['trace']['status']);
        $this->assertSame(1, $result['trace']['verification']['causal_proofs']['count']);
        Http::assertSentCount(2);
    }

    public function test_temporal_links_use_visible_text_and_are_not_sent_as_causal_hypotheses(): void
    {
        $this->decisions();
        $ai = $this->ai();
        $ai->shouldReceive('chatWithHistory')->once()->andReturn($this->response([
            ['text' => 'For CASE-A, **shipment and pickup** occur together.', 'claim_ids' => [0]],
        ]));
        $ai->shouldReceive('chat')->once()->withArgs(function ($system, $user) {
            $input = json_decode($user, true);
            $this->assertSame('For CASE-A, shipment and pickup occur together.', $input['paragraphs'][0]['text']);
            $this->assertArrayNotHasKey('verified_facts', $input);
            return true;
        })->andReturn($this->extraction([[
            'paragraph_id' => 0, 'quote' => 'shipment and pickup occur together.', 'kind' => 'temporal', 'from' => 'shipment', 'to' => 'pickup',
        ]]));
        $result = (new AgentAnswerPresenter($ai))->present('CASE-A?', $this->context(),
            [['text' => 'CASE-A: shipment and pickup happen at the same time.', 'subquestion_id' => 0]]);
        $this->assertSame('accepted', $result['trace']['status']);
        $this->assertSame(0, $result['trace']['causal_extraction']['causal_count']);
        $this->assertSame(.8, config('reasoning.threshold'));
        Http::assertSentCount(1);
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
        $ai = $this->ai();
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
