<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion\Learning;

/**
 * W7 — decides whether a tuning proposal may be applied: it must not make
 * retrieval worse. Implementations return
 *   ['status' => 'passed'|'failed'|'not_measurable'|'skipped', ...metrics]
 * Only `passed` lets the scheduler AUTO-apply; a human may apply any proposal
 * (the gate result is recorded next to their decision).
 */
interface ExpansionTuningGate
{
    /**
     * @param  array{weights: array<string,float>, few_shot: list<array<string,mixed>>}  $tuning
     * @return array<string,mixed>
     */
    public function evaluate(array $tuning): array;
}
