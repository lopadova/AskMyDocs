<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Grounding;

use App\Services\Kb\Grounding\ConfidenceCalculator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * T3.2 — pure-math test suite for ConfidenceCalculator.
 *
 * No DB, no Eloquent — just inputs in, integer 0..100 out. Edge cases
 * deliberately split into named tests so a regression on (e.g.) the
 * refusal-density carve-out is immediately attributable to its rule.
 */
final class ConfidenceCalculatorTest extends TestCase
{
    public function test_zero_chunks_returns_zero(): void
    {
        $score = (new ConfidenceCalculator())->compute(
            primaryChunks: collect(),
            minThreshold: 0.45,
            answerWords: 0,
            citationsCount: 0,
        );

        $this->assertSame(0, $score);
    }

    public function test_high_signal_inputs_yield_confidence_above_80(): void
    {
        // High vector scores + 3 distinct documents + 3 citations on a
        // 200-word (= 2 segments) answer → density >= 1.
        $score = (new ConfidenceCalculator())->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 0.92, 'knowledge_document_id' => 1],
                (object) ['vector_score' => 0.88, 'knowledge_document_id' => 2],
                (object) ['vector_score' => 0.85, 'knowledge_document_id' => 3],
            ]),
            minThreshold: 0.45,
            answerWords: 200,
            citationsCount: 3,
        );

        $this->assertGreaterThanOrEqual(80, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function test_low_signal_inputs_yield_confidence_below_50(): void
    {
        // One marginal chunk, one document, one citation on a 500-word
        // answer (= 5 segments) → density 0.2.
        $score = (new ConfidenceCalculator())->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 0.46, 'knowledge_document_id' => 1],
            ]),
            minThreshold: 0.45,
            answerWords: 500,
            citationsCount: 1,
        );

        $this->assertLessThan(50, $score);
    }

    public function test_perfect_inputs_clamp_at_100(): void
    {
        // Every signal at maximum:
        //  - mean similarity 1.0 → contributes 40
        //  - threshold margin 1.0 (min_used = 1.0) → contributes 20
        //  - diversity 1.0 (3 chunks, 3 docs) → contributes 20
        //  - density 1.0 (citations >= segments) → contributes 20
        //  - total = 100
        $score = (new ConfidenceCalculator())->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 1.0, 'knowledge_document_id' => 1],
                (object) ['vector_score' => 1.0, 'knowledge_document_id' => 2],
                (object) ['vector_score' => 1.0, 'knowledge_document_id' => 3],
            ]),
            minThreshold: 0.45,
            answerWords: 100,
            citationsCount: 5,  // > 1 segment, density caps at 1
        );

        $this->assertSame(100, $score);
    }

    public function test_threshold_at_min_score_zeroes_margin_component(): void
    {
        // min_used === threshold → (0.45 - 0.45) / (1 - 0.45) = 0
        // margin contributes 0; mean = 0.45 → 0.4 * 0.45 = 0.18 = 18 pts
        // diversity = 1/1 = 1 → 20 pts
        // density 1 cite / 1 segment = 1 → 20 pts
        // total ≈ 58
        $score = (new ConfidenceCalculator())->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 0.45, 'knowledge_document_id' => 1],
            ]),
            minThreshold: 0.45,
            answerWords: 100,
            citationsCount: 1,
        );

        $this->assertGreaterThanOrEqual(55, $score);
        $this->assertLessThanOrEqual(60, $score);
    }

    public function test_diversity_penalises_single_document_results(): void
    {
        // Same vector scores, same citations, same answer length —
        // ONLY diversity differs. The 3-doc result MUST score higher
        // than the 1-doc result.
        $calc = new ConfidenceCalculator();

        $oneDoc = $calc->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 0.85, 'knowledge_document_id' => 1],
                (object) ['vector_score' => 0.85, 'knowledge_document_id' => 1],
                (object) ['vector_score' => 0.85, 'knowledge_document_id' => 1],
            ]),
            minThreshold: 0.45,
            answerWords: 100,
            citationsCount: 1,
        );

        $threeDocs = $calc->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 0.85, 'knowledge_document_id' => 1],
                (object) ['vector_score' => 0.85, 'knowledge_document_id' => 2],
                (object) ['vector_score' => 0.85, 'knowledge_document_id' => 3],
            ]),
            minThreshold: 0.45,
            answerWords: 100,
            citationsCount: 1,
        );

        $this->assertLessThan($threeDocs, $oneDoc);
    }

    public function test_citation_density_caps_at_one(): void
    {
        // 5 citations on a 100-word (1 segment) answer → density would
        // be 5, must cap at 1.0 → density component contributes 20 pts.
        $calc = new ConfidenceCalculator();

        $generous = $calc->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 0.85, 'knowledge_document_id' => 1],
            ]),
            minThreshold: 0.45,
            answerWords: 100,
            citationsCount: 5,
        );

        $minimal = $calc->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 0.85, 'knowledge_document_id' => 1],
            ]),
            minThreshold: 0.45,
            answerWords: 100,
            citationsCount: 1,  // already 1.0 density at 1 segment
        );

        // Both at density==1 → identical scores. Caller can't game the
        // metric by spamming citations.
        $this->assertSame($minimal, $generous);
    }

    public function test_zero_answer_words_does_not_penalise_density(): void
    {
        // Refusal path: answer is the i18n "no grounded answer" placeholder
        // which the caller treats as 0 words for scoring purposes.
        // Density carve-out forces 1.0 so refusal isn't double-penalised.
        $score = (new ConfidenceCalculator())->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 0.95, 'knowledge_document_id' => 1],
            ]),
            minThreshold: 0.45,
            answerWords: 0,
            citationsCount: 0,
        );

        // mean 0.95 → 38, margin (0.95-0.45)/0.55 = 0.909 → 18.18,
        // diversity 1.0 → 20, density carved out to 1 → 20. Total ~96.
        $this->assertGreaterThan(90, $score);
    }

    public function test_accepts_array_chunks_in_addition_to_objects(): void
    {
        // KbSearchService results are stdClass; some test fixtures pass
        // associative arrays. The fieldOf() helper must accept both.
        $score = (new ConfidenceCalculator())->compute(
            primaryChunks: collect([
                ['vector_score' => 0.85, 'knowledge_document_id' => 1],
                (object) ['vector_score' => 0.85, 'knowledge_document_id' => 2],
            ]),
            minThreshold: 0.45,
            answerWords: 100,
            citationsCount: 1,
        );

        $this->assertGreaterThan(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function test_threshold_at_one_returns_zero_margin_safely(): void
    {
        // Pathological threshold=1.0 → denominator = 0; the calculator
        // must NOT throw a division-by-zero. Margin component is 0; the
        // other three components still produce a real number.
        $score = (new ConfidenceCalculator())->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 1.0, 'knowledge_document_id' => 1],
            ]),
            minThreshold: 1.0,
            answerWords: 100,
            citationsCount: 1,
        );

        // Mean 1.0 → 40, margin (forced) 0, diversity 1.0 → 20, density 1 → 20.
        $this->assertSame(80, $score);
    }

    public function test_result_is_always_integer(): void
    {
        // Floats happen at every internal step; round() at the end is
        // load-bearing. Verify return type is int (PHP ≥7 strict types
        // would catch this at the call site, but a cast regression
        // could still emit non-int).
        $score = (new ConfidenceCalculator())->compute(
            primaryChunks: collect([
                (object) ['vector_score' => 0.73, 'knowledge_document_id' => 1],
            ]),
            minThreshold: 0.45,
            answerWords: 137,
            citationsCount: 2,
        );

        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }
}
