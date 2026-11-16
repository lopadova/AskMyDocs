<?php

declare(strict_types=1);

namespace Tests\Unit\Decisions;

use App\Services\Kb\Knowledge\KnowledgeContract;
use App\Services\Kb\Knowledge\KnowledgeExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KnowledgeAdmissionTest extends TestCase
{
    private function contract(): array
    {
        $blocks = app(KnowledgeContract::class)->template();
        $blocks['admission']['calibration'] = ['threshold' => 0.9, 'reference' => 'labeled-test-fixture',
            'judge_version' => KnowledgeExtractor::JUDGE_VERSION, 'model' => 'typesafe/jev-1.13-20260917'];
        return ['blocks' => $blocks];
    }

    private function candidate(): array
    {
        return ['type' => 'order', 'namespace' => 'order', 'identifier' => '00123', 'rule' => 'history', 'property' => 'event', 'value' => 'Spedito', 'quote' => 'Ordine 00123 spedito.'];
    }

    private function response(float $association = 0.99): array
    {
        return ['id' => 'test-decision', 'model' => 'typesafe/jev-1.13-20260917', 'usage' => ['cost' => 0.00001], 'answers' => [
            'association' => ['type' => 'noul', 'noul' => $association],
            'admission' => ['type' => 'noul', 'noul' => 0.99],
            'support' => ['type' => 'choice', 'choice' => 'documented', 'confidence' => 0.99,
                'probabilities' => ['documented' => 0.99, 'proposed' => 0.003, 'contradictory' => 0.003, 'insufficient' => 0.004]],
        ]];
    }

    public function test_jev_typed_decision_approves_only_supported_association_and_retains_usage(): void
    {
        Http::fake(['openrouter.ai/api/alpha/decisions' => Http::response($this->response())]);
        $result = app(KnowledgeExtractor::class)->judge($this->candidate(), $this->contract());
        $this->assertSame('accepted', $result['status']);
        $this->assertSame('documented', $result['support']);
        $this->assertSame(0.00001, $result['decision']['usage']['cost']);
        Http::assertSent(fn ($request) => $request['questions']['association']['type'] === 'noul'
            && $request['questions']['support']['type'] === 'choice' && $request['state']['subject']['identifier'] === '00123'
            && $request['state']['claim']['text'] === 'Spedito' && $request['state']['evidence_excerpt'] === 'Ordine 00123 spedito.'
            && $request['model'] === 'typesafe/jev-1.13-20260917');
    }

    public function test_uncertainty_retains_the_paid_response_but_abstains(): void
    {
        Http::fake(['openrouter.ai/api/alpha/decisions' => Http::response($this->response(0.60))]);
        $result = app(KnowledgeExtractor::class)->judge($this->candidate(), $this->contract());
        $this->assertSame('abstained', $result['status']);
        $this->assertSame('test-decision', $result['decision']['id']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_source_scoped_procedure_does_not_ask_for_a_literal_source_identifier(): void
    {
        Http::fake(['openrouter.ai/api/alpha/decisions' => Http::response($this->response())]);
        $case = collect(json_decode(file_get_contents(__DIR__.'/../../../resources/fixtures/knowledge/acceptance.json'), true))->firstWhere('id', 'procedure_single_reply');
        $extractor = app(KnowledgeExtractor::class);
        $candidate = $extractor->validate($case['candidate'], $case['source'], $this->contract());
        $this->assertTrue($candidate['source_identity']);
        $this->assertSame('accepted', $extractor->judge($candidate, $this->contract())['status']);
        Http::assertSent(fn ($request) => ! isset($request['state']['subject']['identifier'])
            && str_contains($request['state']['subject']['identity'], 'source-scoped')
            && ! isset($request['state']['candidate'], $request['state']['quote_start']));
    }

    public function test_a_proposal_can_be_retained_without_promoting_it_to_a_success(): void
    {
        $response = $this->response();
        $response['answers']['support'] = ['type' => 'choice', 'choice' => 'proposed', 'confidence' => 0.99,
            'probabilities' => ['documented' => 0.003, 'proposed' => 0.99, 'contradictory' => 0.003, 'insufficient' => 0.004]];
        Http::fake(['openrouter.ai/api/alpha/decisions' => Http::response($response)]);
        $case = collect(json_decode(file_get_contents(__DIR__.'/../../../resources/fixtures/knowledge/acceptance.json'), true))->firstWhere('id', 'suggestion');
        $extractor = app(KnowledgeExtractor::class);
        $result = $extractor->judge($extractor->validate($case['candidate'], $case['source'], $this->contract()), $this->contract());
        $this->assertSame('accepted', $result['status']);
        $this->assertSame('proposed', $result['support']);
    }

    public function test_a_confident_support_label_cannot_override_an_unsupported_claim(): void
    {
        $response = $this->response();
        $response['answers']['admission']['noul'] = 0.02;
        Http::fake(['openrouter.ai/api/alpha/decisions' => Http::response($response)]);
        $result = app(KnowledgeExtractor::class)->judge($this->candidate(), $this->contract());
        $this->assertSame('abstained', $result['status']);
        $this->assertSame(0.02, app(KnowledgeExtractor::class)->score($result['decision']));
    }

    public function test_low_support_confidence_still_abstains_despite_supported_claim(): void
    {
        $response = $this->response();
        $response['answers']['support']['confidence'] = 0.49;
        Http::fake(['openrouter.ai/api/alpha/decisions' => Http::response($response)]);
        $result = app(KnowledgeExtractor::class)->judge($this->candidate(), $this->contract());
        $this->assertSame('abstained', $result['status']);
        $this->assertSame('test-decision', $result['decision']['id']);
    }

    public function test_legacy_calibration_is_not_reused_for_a_changed_judge(): void
    {
        Http::fake();
        $contract = $this->contract();
        unset($contract['blocks']['admission']['calibration']['judge_version']);
        $result = app(KnowledgeExtractor::class)->judge($this->candidate(), $contract);
        $this->assertSame('calibration_version_mismatch', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_model_change_retains_the_paid_response_but_never_approves(): void
    {
        $response = $this->response();
        $response['model'] = 'typesafe/another-model';
        Http::fake(['openrouter.ai/api/alpha/decisions' => Http::response($response)]);
        $result = app(KnowledgeExtractor::class)->judge($this->candidate(), $this->contract());
        $this->assertSame('abstained', $result['status']);
        $this->assertSame('calibration_model_mismatch', $result['reason']);
        $this->assertSame(0.00001, $result['decision']['usage']['cost']);
    }

    public function test_provider_402_is_an_explicit_abstention_without_an_approval(): void
    {
        Http::fake(['openrouter.ai/api/alpha/decisions' => Http::response(['error' => ['message' => 'Insufficient credits']], 402)]);
        $result = app(KnowledgeExtractor::class)->judge($this->candidate(), $this->contract());
        $this->assertSame('abstained', $result['status']);
        $this->assertSame(402, $result['provider_status']);
        $this->assertArrayNotHasKey('support', $result);
        Http::assertSentCount(1);
    }
}
