<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Metrics;

use App\Services\Kb\Metrics\RetrievalQualityMetrics as M;
use Tests\TestCase;

/**
 * v8.18/W2 — answer-containment@k, a NEW additive capability delegating to
 * padosoft/eval-harness `answer-containment-at-k`. 1.0 when the expected answer
 * appears in any of the top-k retrieved chunk texts, else 0.0.
 */
final class RetrievalQualityMetricsAnswerContainmentTest extends TestCase
{
    public function test_answer_containment_hits_when_answer_in_top_k_text(): void
    {
        $chunks = [
            ['id' => 'a', 'text' => 'The cache TTL is 30 days.'],
            ['id' => 'b', 'text' => 'Unrelated content.'],
        ];
        self::assertSame(1.0, M::answerContainmentAtK($chunks, 'cache TTL is 30 days', 2));
        self::assertSame(0.0, M::answerContainmentAtK($chunks, 'no such phrase', 2));
    }

    public function test_answer_containment_respects_k_window(): void
    {
        $chunks = [
            ['id' => 'a', 'text' => 'Irrelevant.'],
            ['id' => 'b', 'text' => 'The answer is 42.'],
        ];
        self::assertSame(0.0, M::answerContainmentAtK($chunks, 'answer is 42', 1)); // top-1 misses
        self::assertSame(1.0, M::answerContainmentAtK($chunks, 'answer is 42', 2)); // top-2 hits
    }

    public function test_answer_containment_guards_non_positive_k_and_empty_answer(): void
    {
        // Consistency with precisionAtK()/ndcgAtK(): k <= 0 has no top-k → 0.0,
        // and an empty expected answer has nothing to contain → 0.0 (never the
        // degenerate "empty string is trivially contained" → 1.0).
        $chunks = [['id' => 'a', 'text' => 'The answer is 42.']];
        self::assertSame(0.0, M::answerContainmentAtK($chunks, 'answer is 42', 0));
        self::assertSame(0.0, M::answerContainmentAtK($chunks, 'answer is 42', -3));
        self::assertSame(0.0, M::answerContainmentAtK($chunks, '   ', 2));
    }
}
