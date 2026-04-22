<?php

namespace Tests\Unit\Kb\Retrieval;

use App\Services\Kb\Retrieval\CosineCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CosineCalculator::class)]
class CosineCalculatorTest extends TestCase
{
    private CosineCalculator $cosine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cosine = new CosineCalculator();
    }

    public function test_identical_vectors_have_similarity_one(): void
    {
        $v = [0.1, 0.2, 0.3, 0.4];
        $this->assertEqualsWithDelta(1.0, $this->cosine->similarity($v, $v), 1e-9);
    }

    public function test_orthogonal_vectors_have_similarity_zero(): void
    {
        $a = [1.0, 0.0];
        $b = [0.0, 1.0];
        $this->assertEqualsWithDelta(0.0, $this->cosine->similarity($a, $b), 1e-9);
    }

    public function test_empty_vectors_return_zero_without_throwing(): void
    {
        $this->assertSame(0.0, $this->cosine->similarity([], []));
        $this->assertSame(0.0, $this->cosine->similarity([0.1, 0.2], []));
        $this->assertSame(0.0, $this->cosine->similarity([], [0.1, 0.2]));
    }

    public function test_zero_magnitude_returns_zero_without_throwing(): void
    {
        $this->assertSame(0.0, $this->cosine->similarity([0.0, 0.0, 0.0], [1.0, 2.0, 3.0]));
    }

    public function test_dimension_mismatch_fails_fast_with_invalid_argument_exception(): void
    {
        // Regression for Copilot PR #11 comment: silently truncating
        // mismatched vectors hides a misconfigured embeddings provider.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dimension mismatch');
        $this->cosine->similarity([0.1, 0.2, 0.3], [0.4, 0.5]);
    }

    public function test_mismatch_message_suggests_the_fix(): void
    {
        // Operators debugging a retrieval regression must see the hint to
        // flush embedding_cache + re-ingest after a provider switch.
        try {
            $this->cosine->similarity([0.1], [0.1, 0.2]);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('embedding_cache', $e->getMessage());
            $this->assertStringContainsString('re-ingest', $e->getMessage());
        }
    }
}
