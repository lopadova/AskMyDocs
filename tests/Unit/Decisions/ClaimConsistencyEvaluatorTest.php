<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\ClaimConsistencyEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ClaimConsistencyEvaluatorTest extends TestCase
{
    private function grounding(): array
    {
        return ['valid' => true, 'claims' => [
            ['text' => 'On June 4 the team was waiting for the amendment.', 'subquestion_id' => 0],
            ['text' => 'No case communication confirms the amendment was completed.', 'subquestion_id' => 0],
            ['text' => 'At noon Ada confirmed: amendment completed.', 'subquestion_id' => 0],
            ['text' => 'The ticket does not confirm the later payment.', 'subquestion_id' => 1],
        ], 'subquestions' => [['status' => 'answered'], ['status' => 'answered']]];
    }

    public function test_a_global_denial_is_removed_without_removing_the_positive_update_or_unrelated_gap(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::fake(function ($request) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) $answers[$key] = ['type' => 'noul', 'noul' => $key === 'consistent_1' ? .02 : .98];
            return Http::response(['id' => 'decision', 'model' => 'test', 'answers' => $answers]);
        });
        $result = app(ClaimConsistencyEvaluator::class)->apply('What happened?', $this->grounding());
        $this->assertTrue($result['valid']);
        $this->assertTrue($result['partial']);
        $this->assertSame([0, 2, 3], array_keys(array_intersect(array_column($this->grounding()['claims'], 'text'), array_column($result['claims'], 'text'))));
        $this->assertSame('answered', $result['subquestions'][1]['status']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => count($r['state']['source_verified_statements']) === 4 && ! isset($r['state']['sources']));
    }

    public function test_a_positive_receipt_cannot_be_rejected_by_uncertain_denial_consistency(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::fake(function ($request) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                $answers[$key] = ['type' => 'noul', 'noul' => match ($key) {
                    'denial_0' => .02, 'denial_1' => .98, 'consistent_1' => .98,
                    default => .64,
                }];
            }
            return Http::response(['id' => 'decision', 'model' => 'test', 'answers' => $answers]);
        });
        $grounding = ['valid' => true, 'claims' => [
            ['text' => 'The warehouse received product ZZ04, linked to order B.', 'subquestion_id' => 0],
            ['text' => 'Zero units of expected product XY03 were registered; payment is unconfirmed.', 'subquestion_id' => 0],
        ]];
        $result = app(ClaimConsistencyEvaluator::class)->apply('Compare the return contents.', $grounding);
        $this->assertSame($grounding['claims'], $result['claims']);
        Http::assertSent(fn ($request) => isset($request['questions']['consistent_1']) && ! isset($request['questions']['consistent_0']));
    }

    public function test_unavailable_consistency_never_approves_a_potentially_contradictory_answer(): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $result = app(ClaimConsistencyEvaluator::class)->apply('What happened?', $this->grounding());
        $this->assertFalse($result['valid']);
        $this->assertSame([], $result['claims']);
        $this->assertSame('unverified', $result['subquestions'][0]['status']);
    }

    public function test_failed_source_validation_cannot_be_overridden_by_a_consistency_decision(): void
    {
        Http::preventStrayRequests();
        $grounding = [...$this->grounding(), 'valid' => false];
        $this->assertSame($grounding, app(ClaimConsistencyEvaluator::class)->apply('What happened?', $grounding));
        Http::assertNothingSent();
    }
}
