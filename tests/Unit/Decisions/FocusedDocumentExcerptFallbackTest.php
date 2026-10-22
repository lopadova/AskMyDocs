<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\FocusedClaimEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FocusedDocumentExcerptFallbackTest extends TestCase
{
    private function decide(float $safe = .95, float $relevant = .95, string $category = 'supported_relevant', float $confidence = .67): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($safe, $relevant, $category, $confidence) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                if ($question['type'] === 'noul') {
                    $answers[$key] = ['type' => 'noul', 'noul' => str_starts_with($key, 'excerpt_relevance_') ? $relevant
                        : (str_starts_with($key, 'excerpt_') ? $safe : $relevant)];
                } else {
                    $probabilities = array_fill_keys(array_keys($question['criteria']), (1 - $confidence) / 4);
                    $probabilities[$category] = $confidence;
                    $answers[$key] = ['type' => 'choice', 'choice' => $category, 'confidence' => $confidence, 'probabilities' => $probabilities];
                }
            }
            return Http::response(['id' => 'excerpt-test', 'model' => 'jev-test', 'answers' => $answers]);
        });
    }

    private function evaluate(string $quote = 'Please replace the incorrect address.', ?string $source = null, string $hash = 'h'): array
    {
        $source ??= "CASE-A email.\n\nCustomer: ".$quote."\n\nNo merchant reply is recorded.";
        $sub = ['topic' => 'order', 'identifiers' => ['CASE-A'], 'fields' => ['problem', 'request']];
        return app(FocusedClaimEvaluator::class)->evaluate('What change was requested for CASE-A?',
            ['documents' => [['document_id' => 7, 'title' => 'CASE-A', 'evidence' => [[
                'content' => $source, 'evidence_hash' => 'h', 'complete_source' => true,
            ]]]]], [['text' => 'The merchant confirmed the change for CASE-A.', 'quote' => $quote,
                'document_id' => 7, 'evidence_hash' => $hash]],
            ['language' => 'it', 'focus' => $sub, 'subquestions' => [$sub]]);
    }

    public function test_a_checked_excerpt_stays_separate_from_an_uncertain_business_claim(): void
    {
        $this->decide();
        $result = $this->evaluate();
        $this->assertFalse($result['valid']);
        $this->assertSame([], $result['claims']);
        $this->assertStringContainsString('Please replace the incorrect address.', $result['source_excerpts'][0]['text']);
        $this->assertStringNotContainsString('merchant confirmed', $result['source_excerpts'][0]['text']);
        $this->assertFalse($result['source_excerpts'][0]['business_claim_approved']);
        $check = $result['semantic_validation'][0]['checks'][0];
        $this->assertSame(.67, $check['probability']);
        $this->assertNull($check['fallback']);
        $this->assertTrue($result['partial']);
        Http::assertSent(fn ($request) => isset($request['questions']['excerpt_claim_0'])
            && ! str_contains(json_encode($request['state']), 'merchant confirmed'));
    }

    public function test_below_threshold_context_validation_cannot_authorize_the_excerpt(): void
    {
        $this->decide(safe: .79);
        $this->assertSame([], $this->evaluate()['source_excerpts']);
    }

    public function test_a_literal_but_misleadingly_cropped_fragment_is_rejected(): void
    {
        $this->decide(safe: .03);
        $result = $this->evaluate('send the refund', 'CASE-A: The merchant did not send the refund.');
        $this->assertFalse($result['valid']);
        $this->assertSame([], $result['claims']);
        $this->assertSame([], $result['source_excerpts']);
    }

    public function test_the_unchanged_original_question_relevance_gate_still_applies(): void
    {
        $this->decide(relevant: .79);
        $this->assertSame([], $this->evaluate()['source_excerpts']);
    }

    public function test_a_bad_hash_or_nonliteral_quote_cannot_be_recovered(): void
    {
        $this->decide();
        $this->assertFalse($this->evaluate(hash: 'wrong')['valid']);
        $this->assertFalse($this->evaluate('An invented quotation.', 'CASE-A: Please change the address.')['valid']);
    }

    public function test_confident_rejection_is_not_overridden_even_by_a_literal_quote(): void
    {
        foreach (['unsupported', 'conflicting', 'supported_off_topic'] as $category) {
            $this->decide(category: $category, confidence: .95);
            $this->assertFalse($this->evaluate()['valid']);
            Http::assertNotSent(fn ($request) => isset($request['questions']['excerpt_claim_0']));
        }
    }

    public function test_source_markup_remains_inert_when_the_excerpt_is_rendered(): void
    {
        $this->decide();
        $quote = 'CASE-A: <img src="https://tracker.example/image"> [link](javascript:alert(1))';
        $result = $this->evaluate($quote);
        $this->assertFalse($result['valid']);
        $html = (string) (new \League\CommonMark\CommonMarkConverter)->convert($result['source_excerpts'][0]['text']);
        $this->assertStringNotContainsString('<img ', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_long_passages_are_not_truncated_to_make_a_fallback(): void
    {
        $this->decide();
        $this->assertFalse($this->evaluate(str_repeat('A long original passage. ', 40))['valid']);
    }

    public function test_a_short_anchor_keeps_the_surrounding_paragraph_and_negation(): void
    {
        $this->decide();
        $result = $this->evaluate('send the refund', "CASE-A: The merchant did not\r\nsend the refund.");
        Http::assertSent(fn ($request) => isset($request['state']['original_sources'])
            && ! str_contains(implode('', $request['state']['original_sources']), "\r"));
        $this->assertStringContainsString("did not\n    send the refund", $result['source_excerpts'][0]['text']);
        $this->assertSame([], $result['claims']);
    }
}
