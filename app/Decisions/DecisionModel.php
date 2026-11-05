<?php

declare(strict_types=1);

namespace App\Decisions;

interface DecisionModel
{
    /** @param array<string,mixed> $state @param array<string,array<string,mixed>> $questions */
    public function decide(array $state, array $questions): DecisionResult;
}
