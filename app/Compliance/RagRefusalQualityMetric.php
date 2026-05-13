<?php

namespace App\Compliance;

use Padosoft\AiActCompliance\BiasMonitoring\Contracts\CohortParityMetric;

class RagRefusalQualityMetric implements CohortParityMetric
{
    public function compute(array $context = []): array
    {
        $cohort = (string) ($context['cohort'] ?? 'global');
        $total = max(1, (int) ($context['total'] ?? 1));
        $refusals = max(0, (int) ($context['refusals'] ?? 0));

        $rate = $refusals / $total;

        return [
            'cohort' => $cohort,
            'score' => round(1 - $rate, 4),
            'delta' => round(($context['baseline'] ?? 0.95) - (1 - $rate), 4),
            'refusal_rate' => round($rate, 4),
        ];
    }
}
