<?php

namespace Tests\Feature\Kb\Retrieval;

use App\Services\Kb\Retrieval\CosineCalculator;

/**
 * Test double for CosineCalculator — returns a fixed similarity for every
 * pair so unit tests don't rely on pgvector or manual dot-products.
 */
class FakeCosineCalculator extends CosineCalculator
{
    public function __construct(private readonly float $fixedValue = 0.80)
    {
    }

    public function similarity(array $a, array $b): float
    {
        return $this->fixedValue;
    }
}
