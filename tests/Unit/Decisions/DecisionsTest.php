<?php

declare(strict_types=1);

namespace Tests\Unit\Decisions;

use App\Decisions\DecisionException;
use App\Decisions\Decisions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DecisionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.providers.openrouter.key', 'test-only-key');
        config()->set('decisions.default', 'jev');
        config()->set('decisions.models.jev.driver', \App\Decisions\Models\JevDecisionModel::class);
        config()->set('decisions.models.jev.model', 'typesafe/jev-1.13');
        config()->set('decisions.models.jev.timeout', 15);
        config()->set('decisions.max_state_bytes', 16000);
    }

    public function test_one_request_can_return_typed_yes_no_choice_and_score(): void
    {
        Http::fake(['openrouter.ai/api/alpha/decisions' => Http::response([
            'id' => 'dec-1', 'model' => 'typesafe/jev-1.13-20260917',
            'answers' => [
                'relevant' => ['type' => 'noul', 'noul' => 0.96],
                'category' => ['type' => 'choice', 'choice' => 'order', 'confidence' => 0.9,
                    'probabilities' => ['order' => 0.9, 'shipment' => 0.1]],
                'urgency' => ['type' => 'score', 'score' => 1.8, 'confidence' => 0.9,
                    'probabilities' => ['0' => 0.1, '1' => 0.1, '2' => 0.8]],
            ],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'cost' => 0.00001],
        ])]);

        $result = Decisions::using('jev')->withState(['question' => 'Ordine 88512?'])
            ->relevance()->choice('category', 'What is this?', ['order' => 'Order', 'shipment' => 'Shipment'])
            ->score('urgency', 'How urgent?', ['Low', 'Medium', 'High'])->decide();

        $this->assertTrue($result->toBool('relevant', 0.8));
        $this->assertSame('order', $result->answers['category']['choice']);
        $this->assertSame(1.8, $result->answers['urgency']['score']);
        $this->assertSame('dec-1', json_decode($result->toJson(), true)['id']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/alpha/decisions'
            && $request['questions']['relevant']['type'] === 'noul');
    }

    public function test_confident_no_is_false_and_uncertainty_throws(): void
    {
        Http::fakeSequence()->push($this->yesNoResponse(0.04))->push($this->yesNoResponse(0.5));
        $builder = Decisions::using()->withState(['text' => 'example'])->relevance();
        $this->assertFalse($builder->decide()->toBool('relevant', 0.8));
        $this->expectException(DecisionException::class);
        $builder->decide()->toBool('relevant', 0.8);
    }

    public function test_invalid_probability_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->yesNoResponse(1.2))]);
        $this->expectException(DecisionException::class);
        Decisions::using()->withState(['text' => 'example'])->relevance()->decide();
    }

    public function test_malformed_json_is_rejected(): void
    {
        Http::fake(['*' => Http::response('not-json', 200, ['Content-Type' => 'application/json'])]);
        $this->expectException(DecisionException::class);
        Decisions::using()->withState(['text' => 'example'])->relevance()->decide();
    }

    public function test_connection_failure_is_reported_as_unavailable(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $this->expectException(DecisionException::class);
        Decisions::using()->withState(['text' => 'example'])->relevance()->decide();
    }

    public function test_provider_failure_is_not_mistaken_for_no(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $this->expectException(DecisionException::class);
        Decisions::using()->withState(['text' => 'example'])->relevance()->decide();
    }

    public function test_sensitive_state_fields_are_redacted_before_the_provider_call(): void
    {
        Http::fake(['*' => Http::response($this->yesNoResponse(0.9))]);
        Decisions::using()->withState([
            'text' => 'Ordine 88512', 'credentials' => ['api_key' => 'private-value'],
        ])->relevance()->decide();
        Http::assertSent(fn ($request) => $request['state']['credentials'] === '[REDACTED]'
            && $request['state']['text'] === 'Ordine 88512');
    }

    public function test_sanitized_unicode_state_is_accepted_at_the_exact_byte_limit(): void
    {
        Http::fake(['*' => Http::response($this->yesNoResponse(0.9))]);
        $state = ['text' => 'Città 東京', 'nested' => ['password' => 'x']];
        $expected = ['text' => 'Città 東京', 'nested' => ['password' => '[REDACTED]']];
        config(['decisions.max_state_bytes' => strlen(json_encode($expected, JSON_UNESCAPED_UNICODE))]);
        Decisions::using()->withState($state)->relevance()->decide();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['state'] === $expected);
    }

    public function test_redaction_expansion_cannot_bypass_the_state_limit(): void
    {
        Http::fake();
        $state = ['text' => 'Città 東京', 'nested' => ['password' => 'x']];
        config(['decisions.max_state_bytes' => strlen(json_encode($state, JSON_UNESCAPED_UNICODE))]);
        try {
            Decisions::using()->withState($state)->relevance()->decide();
            $this->fail('Sanitized state must not exceed the byte limit.');
        } catch (DecisionException $e) {
            $this->assertSame('Decision state exceeds the configured limit.', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_larger_state_keeps_the_independent_question_budget(): void
    {
        Http::fake();
        config(['decisions.max_state_bytes' => 32768]);
        $request = Decisions::using()->withState(['text' => str_repeat('state ', 3000)]);
        for ($i = 0; $i < 10; $i++) {
            $request = $request->choice('item_'.$i, str_repeat('instructions ', 50), [
                'yes' => str_repeat('supported ', 90), 'no' => str_repeat('unsupported ', 80),
            ]);
        }
        try {
            $request->decide();
            $this->fail('Larger state must not bypass the questions limit.');
        } catch (DecisionException $e) {
            $this->assertSame('Decision questions exceed the request limit.', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    /** @return array<string,mixed> */
    private function yesNoResponse(float $probability): array
    {
        return ['id' => 'dec-2', 'model' => 'typesafe/jev-1.13',
            'answers' => ['relevant' => ['type' => 'noul', 'noul' => $probability]],
            'usage' => ['cost' => 0.00001]];
    }
}
