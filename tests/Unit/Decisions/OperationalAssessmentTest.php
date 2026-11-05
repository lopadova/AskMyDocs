<?php

declare(strict_types=1);

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\FocusedClaimEvaluator;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OperationalAssessmentTest extends TestCase
{
    private function evidence(): array
    {
        return ['documents' => [['document_id' => 1, 'title' => 'ORDER-A', 'evidence' => [[
            'content' => 'ORDER-A, 13:52: the customer says the barcode is missing.', 'evidence_hash' => 'old', 'complete_source' => true,
        ]]], ['document_id' => 2, 'title' => 'ORDER-A', 'evidence' => [[
            'content' => 'ORDER-A, 15:38: the customer confirms the barcode is present.', 'evidence_hash' => 'new', 'complete_source' => true,
        ]]]]];
    }

    #[DataProvider('assessmentProbabilities')]
    public function test_assessments_use_a_narrow_decision_with_the_existing_threshold(float $probability, bool $accepted): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($probability) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                $this->assertSame('noul', $question['type']);
                if ($key === 'claim_0') {
                    $this->assertStringContainsString('source-bound assessment', $question['instructions']);
                    $this->assertStringContainsString('whole-case closure', $question['criteria']['false']);
                    $this->assertCount(2, $request['state']['sources']);
                } elseif ($key === 'relevance_0') {
                    $this->assertStringContainsString('intervention remains necessary', $question['criteria']['true']);
                }
                $answers[$key] = ['type' => 'noul', 'noul' => $key === 'claim_0' ? $probability : .99];
            }
            return Http::response(['model' => 'jev-test', 'answers' => $answers]);
        });
        $focus = ['topic' => 'order', 'identifiers' => ['ORDER-A'], 'aspect' => 'next_steps', 'fields' => []];
        $result = app(FocusedClaimEvaluator::class)->evaluate('Do we need to do anything?', $this->evidence(), [[
            'text' => 'For the ORDER-A barcode issue, the exchange does not indicate a need to send it again: the customer confirms it is present at 15:38.',
            'document_id' => 2, 'evidence_hash' => 'new', 'subquestion_id' => 0,
        ]], ['focus' => $focus, 'subquestions' => [$focus]]);
        $this->assertSame($accepted, $result['valid']);
        $check = $result['semantic_validation'][0]['checks'][0];
        $this->assertSame('source_bound_next_steps', $check['validation_kind']);
        $this->assertSame(.8, $check['threshold']);
        $this->assertNull($check['fallback']);
        Http::assertSentCount($accepted ? 2 : 1);
    }

    public static function assessmentProbabilities(): array
    {
        return [[.96, true], [.79, false], [.03, false]];
    }

    public function test_next_steps_cannot_bypass_source_hash_validation(): void
    {
        Http::preventStrayRequests();
        $focus = ['topic' => 'order', 'identifiers' => ['ORDER-A'], 'aspect' => 'next_steps', 'fields' => []];
        $result = app(FocusedClaimEvaluator::class)->evaluate('Any actions?', $this->evidence(), [[
            'text' => 'ORDER-A needs no new barcode.', 'document_id' => 2, 'evidence_hash' => 'invented',
        ]], ['focus' => $focus, 'subquestions' => [$focus]]);
        $this->assertFalse($result['valid']);
        $this->assertSame('source_hash_mismatch', $result['semantic_validation'][0]['checks'][0]['reason']);
        Http::assertNothingSent();
    }
}
