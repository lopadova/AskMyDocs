<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Models\ChatLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Padosoft\AiActCompliance\BiasMonitoring\Contracts\CohortParityMetric;

/**
 * v6.0/W7 — Refusal-rate parity metric per RAG cohort.
 *
 * Tracks how often the assistant refuses (no_relevant_context /
 * llm_self_refusal) per cohort dimension (project / provider / model).
 * Higher refusal rates in a single cohort indicate coverage gaps or
 * training bias that the operator must investigate under AI Act Art. 10
 * (data + cohort governance) and Art. 15 (accuracy + robustness).
 *
 * Implements the upstream `CohortParityMetric` interface so the package
 * `BiasMonitoring/Services/BiasMonitorService` can plug this metric into
 * its drift detector without knowing about RAG specifics.
 *
 * `compute()` accepts an optional `total` / `refusals` precomputed pair
 * for callers that already have aggregate counts (eval-harness manifest,
 * unit tests). Without them, it queries `chat_logs` directly using the
 * tenant context.
 */
final class RagRefusalQualityMetric implements CohortParityMetric
{
    public function __construct(
        private readonly ?TenantContext $tenantContext = null,
    ) {}

    /**
     * @param  array{
     *   cohort?:string|null,
     *   dimension?:string|null,
     *   window_days?:int,
     *   baseline?:float,
     *   tenant_id?:string|null,
     *   total?:int,
     *   refusals?:int
     * }  $context
     * @return array<string, mixed>
     */
    public function compute(array $context = []): array
    {
        $dimension = (string) ($context['dimension'] ?? 'project');
        $cohort = isset($context['cohort']) && $context['cohort'] !== ''
            ? (string) $context['cohort']
            : 'global';
        $windowDays = max(1, (int) ($context['window_days'] ?? 7));
        $baseline = (float) ($context['baseline'] ?? config('compliance.bias.baseline_parity', 0.95));
        $tenantId = $context['tenant_id'] ?? $this->resolveTenantId();

        if (array_key_exists('total', $context) && array_key_exists('refusals', $context)) {
            $total = max(1, (int) $context['total']);
            $refusals = max(0, (int) $context['refusals']);
        } else {
            try {
                $window = now()->subDays($windowDays);
                [$total, $refusals] = $this->queryCounts($tenantId, $window, $dimension, $cohort);
            } catch (\Throwable) {
                $total = max(1, (int) ($context['total'] ?? 1));
                $refusals = max(0, (int) ($context['refusals'] ?? 0));
            }
        }

        $total = max(1, $total);
        $refusalRate = $refusals / $total;
        $score = round(1 - $refusalRate, 4);
        $delta = round($baseline - $score, 4);

        return [
            'metric' => 'rag_refusal_quality',
            'dimension' => $dimension,
            'cohort' => $cohort,
            'tenant_id' => $tenantId,
            'window_days' => $windowDays,
            'baseline' => $baseline,
            'score' => $score,
            'refusal_rate' => round($refusalRate, 4),
            'delta' => $delta,
            'total' => $total,
            'refusals' => $refusals,
            'flagged' => $delta > 0.05,
        ];
    }

    /**
     * Compute the metric for every cohort segment in a single batch
     * (eval-harness manifest entry + admin SPA bias monitor screen).
     *
     * @return list<array<string, mixed>>
     */
    public function computeAll(string $dimension = 'project', int $windowDays = 7): array
    {
        $tenantId = $this->resolveTenantId();
        $window = now()->subDays($windowDays);
        $column = $this->cohortColumn($dimension);

        $rows = $this->baseQuery($tenantId, $window)
            ->select([
                $column . ' as cohort',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when refusal_reason is not null and refusal_reason != '' then 1 else 0 end) as refusals"),
            ])
            ->groupBy('cohort')
            ->get();

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->compute([
                'dimension' => $dimension,
                'cohort' => (string) ($row->cohort ?? 'global'),
                'window_days' => $windowDays,
                'tenant_id' => $tenantId,
                'total' => (int) $row->total,
                'refusals' => (int) $row->refusals,
            ]);
        }
        return $results;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function queryCounts(?string $tenantId, \DateTimeInterface $window, string $dimension, string $cohort): array
    {
        $query = $this->baseQuery($tenantId, $window);
        if ($cohort !== 'global') {
            $query->where($this->cohortColumn($dimension), $cohort);
        }
        $total = (int) (clone $query)->count();
        $refusals = (int) (clone $query)
            ->whereNotNull('refusal_reason')
            ->where('refusal_reason', '!=', '')
            ->count();
        return [$total, $refusals];
    }

    private function baseQuery(?string $tenantId, \DateTimeInterface $window)
    {
        $query = ChatLog::query()->where('created_at', '>=', $window);
        if ($tenantId !== null && $tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }
        return $query;
    }

    private function cohortColumn(string $dimension): string
    {
        return match ($dimension) {
            'provider' => 'ai_provider',
            'model' => 'ai_model',
            default => 'project_key',
        };
    }

    private function resolveTenantId(): ?string
    {
        if ($this->tenantContext === null) {
            return null;
        }
        try {
            return $this->tenantContext->current();
        } catch (\Throwable) {
            return null;
        }
    }
}
