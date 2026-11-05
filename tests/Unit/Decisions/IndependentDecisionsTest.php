<?php

namespace Tests\Unit\Decisions;

use App\Decisions\DecisionException;
use App\Decisions\Decisions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IndependentDecisionsTest extends TestCase
{
    private function request(): Decisions
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
        return Decisions::using()->withState(['text' => 'Two independent cases.'])
            ->yesNo('good', 'Is the first case supported?', ['true' => 'Supported', 'false' => 'Unsupported'])
            ->choice('bad', 'Classify the second case', ['supported' => 'Supported', 'unsupported' => 'Unsupported']);
    }

    private function response(): array
    {
        return ['model' => 'jev-test', 'answers' => ['good' => ['type' => 'noul', 'noul' => .95],
            'bad' => ['type' => 'choice', 'choice' => 'supported', 'probabilities' => ['supported' => .34, 'unsupported' => .66], 'confidence' => .2]]];
    }

    public function test_malformed_item_abstains_while_valid_independent_answer_keeps_its_original_probability(): void
    {
        $request = $this->request();
        Http::fake(fn () => Http::response($this->response()));
        $result = $request->decideIndependently();
        $this->assertSame(.95, $result->answers['good']['probability_true']);
        $this->assertTrue($result->toBool('good', .8));
        $this->assertArrayNotHasKey('bad', $result->answers);
        $this->assertArrayHasKey('bad', $result->answerErrors);
        $this->assertSame($result->answerErrors, $result->toArray()['answer_errors']);
        Http::assertSentCount(1);
    }

    public function test_default_atomic_contract_still_rejects_the_whole_malformed_packet(): void
    {
        $request = $this->request();
        Http::fake(fn () => Http::response($this->response()));
        $this->expectException(DecisionException::class);
        $request->decide();
    }

    public function test_missing_independent_answer_is_neither_approved_nor_converted_into_a_confident_no(): void
    {
        $request = $this->request();
        $body = $this->response();
        unset($body['answers']['bad']);
        Http::fake(fn () => Http::response($body));
        $result = $request->decideIndependently();
        $this->assertTrue($result->toBool('good', .8));
        $this->assertArrayHasKey('bad', $result->answerErrors);
        $this->expectException(DecisionException::class);
        $result->toBool('bad', .8);
    }

    public function test_unexpected_answer_keys_still_reject_the_packet_in_independent_mode(): void
    {
        $request = $this->request();
        $body = $this->response();
        $body['answers']['foreign'] = ['type' => 'noul', 'noul' => 1];
        Http::fake(fn () => Http::response($body));
        $this->expectException(DecisionException::class);
        $request->decideIndependently();
    }

    public function test_distribution_tolerance_handles_decimal_rounding_without_changing_probabilities(): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['model' => 'jev-test', 'answers' => ['item' => [
            'type' => 'choice', 'choice' => 'supported', 'confidence' => .91,
            'probabilities' => ['supported' => .93, 'unsupported' => .04, 'other' => .02],
        ]]]));
        $result = Decisions::using()->withState(['text' => 'Recorded values.'])->choice('item', 'Classify',
            ['supported' => 'Supported', 'unsupported' => 'Unsupported', 'other' => 'Other'])->decide();
        $this->assertSame(.93, $result->answers['item']['probabilities']['supported']);
        $this->assertSame(.02, $result->answers['item']['probabilities']['other']);
    }

    public function test_distribution_outside_the_existing_tolerance_is_still_rejected(): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['model' => 'jev-test', 'answers' => ['item' => [
            'type' => 'choice', 'choice' => 'supported', 'confidence' => .91,
            'probabilities' => ['supported' => .93, 'unsupported' => .03, 'other' => .02],
        ]]]));
        $this->expectException(DecisionException::class);
        Decisions::using()->withState(['text' => 'Recorded values.'])->choice('item', 'Classify',
            ['supported' => 'Supported', 'unsupported' => 'Unsupported', 'other' => 'Other'])->decide();
    }
}
