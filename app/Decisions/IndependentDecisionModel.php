<?php

declare(strict_types=1);

namespace App\Decisions;

/** Opt-in independent questions: an invalid item abstains without approving or erasing another. */
interface IndependentDecisionModel extends DecisionModel
{
    public function decideIndependently(array $state, array $questions): DecisionResult;
}
