<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval;

use App\Services\Kb\Retrieval\TagOverlapScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TagOverlapScorerTest extends TestCase
{
    #[Test]
    public function returns_zero_for_empty_inputs(): void
    {
        $scorer = new TagOverlapScorer();
        $this->assertSame(0.0, $scorer->score([], ['a']));
        $this->assertSame(0.0, $scorer->score(['a'], []));
        $this->assertSame(0.0, $scorer->score([], []));
    }

    #[Test]
    public function computes_jaccard_for_partial_overlap(): void
    {
        $scorer = new TagOverlapScorer();
        // Intersection {b} | Union {a, b, c} → 1/3 ≈ 0.333
        $score = $scorer->score(['a', 'b'], ['b', 'c']);
        $this->assertEqualsWithDelta(1 / 3, $score, 0.0001);
    }

    #[Test]
    public function returns_one_for_identical_tag_sets(): void
    {
        $scorer = new TagOverlapScorer();
        $this->assertSame(1.0, $scorer->score(['arch', 'cache'], ['arch', 'cache']));
    }

    #[Test]
    public function is_case_and_whitespace_insensitive(): void
    {
        $scorer = new TagOverlapScorer();
        $this->assertSame(1.0, $scorer->score([' Architecture '], ['architecture']));
    }
}
