<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion\Learning;

use App\Services\Kb\Benchmark\BenchmarkRunner;
use App\Services\Kb\Retrieval\Expansion\QueryExpansionService;
use App\Support\TenantContext;
use Throwable;

/**
 * W7 — the default gate: benchmark v2 run twice on its own labelled corpus,
 * once with the CURRENT (neutral) expansion tuning and once with the PROPOSED
 * one ({@see ActiveExpansionTuning::with()}), compared on nDCG@k and MRR.
 *
 *  - `passed`        candidate ≥ baseline − tolerance on both metrics;
 *  - `failed`        a regression beyond the tolerance;
 *  - `not_measurable` the expansion model is OFF here, so the proposal cannot
 *                    change the benchmark — reported, and auto-apply refuses it
 *                    (fail-closed: "no evidence" is never "no regression").
 *
 * Runs in the CLI / queued job, never inside an HTTP request (it ingests the
 * benchmark corpus and makes embedding + expansion calls).
 */
final class BenchmarkExpansionTuningGate implements ExpansionTuningGate
{
    /**
     * @param  (\Closure(string $corpus, string $queries, string $project): array<string,mixed>)|null  $run
     *         test seam for the one benchmark run (the runner is final); null = the real runner
     */
    public function __construct(
        private readonly BenchmarkRunner $runner,
        private readonly QueryExpansionService $expansion,
        private readonly ?\Closure $run = null,
    ) {}

    /** @return array<string,mixed> */
    private function runOnce(string $corpus, string $queries, string $project): array
    {
        return $this->run !== null ? ($this->run)($corpus, $queries, $project) : $this->runner->run($corpus, $queries, $project, 5, false, 'v2');
    }

    public function evaluate(array $tuning): array
    {
        if (! $this->expansion->enabled()) {
            return ['status' => 'not_measurable', 'reason' => 'query_expansion_disabled'];
        }

        $corpus = base_path('resources/benchmark/v2/corpus');
        $queries = base_path('resources/benchmark/v2/queries.yaml');
        $project = (string) config('kb.expansion_learning.gate_project', 'expansion-gate');
        $tolerance = max(0.0, (float) config('kb.expansion_learning.gate_tolerance', 0.0));

        // The benchmark INGESTS its labelled corpus into the active tenant: run
        // it in a dedicated, non-operational tenant (never the customer's, R30)
        // and with signal recording off, so gate traffic never becomes data.
        $tenants = app(TenantContext::class);
        $previous = $tenants->current();
        $tenants->set((string) config('kb.expansion_learning.gate_tenant', 'system-expansion-gate'));
        try {
            [$baseline, $candidate] = ExpansionSignalRecorder::suppressed(fn (): array => [
                ActiveExpansionTuning::with(ActiveExpansionTuning::neutral(), fn (): array => $this->runOnce($corpus, $queries, $project)),
                ActiveExpansionTuning::with($tuning, fn (): array => $this->runOnce($corpus, $queries, $project)),
            ]);
        } catch (Throwable $e) {
            return ['status' => 'not_measurable', 'reason' => 'benchmark_error', 'error_class' => $e::class];
        } finally {
            $tenants->set($previous);
        }

        $metrics = static fn (array $card): array => [
            'ndcg_at_k' => (float) ($card['aggregate']['ndcg_at_k'] ?? 0.0),
            'mrr' => (float) ($card['aggregate']['mrr'] ?? 0.0),
            'corpus_failures' => count((array) ($card['corpus_failures'] ?? [])),
        ];
        $b = $metrics($baseline);
        $c = $metrics($candidate);
        if ($b['corpus_failures'] > 0 || $c['corpus_failures'] > 0) {
            return ['status' => 'not_measurable', 'reason' => 'partial_corpus', 'baseline' => $b, 'candidate' => $c];
        }
        $passed = $c['ndcg_at_k'] >= $b['ndcg_at_k'] - $tolerance && $c['mrr'] >= $b['mrr'] - $tolerance;

        return ['status' => $passed ? 'passed' : 'failed', 'tolerance' => $tolerance, 'baseline' => $b, 'candidate' => $c];
    }
}
