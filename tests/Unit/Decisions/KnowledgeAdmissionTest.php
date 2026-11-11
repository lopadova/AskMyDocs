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
        $blocks['admission']['calibration'] = ['threshold' => 0.9, 'reference' => 'labeled-test-fixture'];
        return ['blocks' => $blocks];
    }

    private function candidate(): array
    {
        return ['type' => 'order', 'namespace' => 'order', 'identifier' => '00123', 'rule' => 'history', 'property' => 'event', 'value' => 'Spedito', 'quote' => 'Ordine 00123 spedito.'];
    }

    private function response(float $association = 0.99): array
    {
        return ['id' => 'test-decision', 'model' => 'typesafe/jev-1.13', 'usage' => ['cost' => 0.00001], 'answers' => [
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
            && $request['questions']['support']['type'] === 'choice' && $request['state']['candidate']['identifier'] === '00123');
    }

    public function test_uncertainty_retains_the_paid_response_but_abstains(): void
    {
        Http::fake(['openrouter.ai/api/alpha/decisions' => Http::response($this->response(0.60))]);
        $result = app(KnowledgeExtractor::class)->judge($this->candidate(), $this->contract());
        $this->assertSame('abstained', $result['status']);
        $this->assertSame('test-decision', $result['decision']['id']);
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
