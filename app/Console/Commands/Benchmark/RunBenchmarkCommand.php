<?php

declare(strict_types=1);

namespace App\Console\Commands\Benchmark;

use App\Services\Kb\Benchmark\BenchmarkRunner;
use App\Services\Kb\Benchmark\StubEmbeddingCache;
use App\Services\Kb\EmbeddingCacheService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Retrieval-quality benchmark runner. Ingests the labelled corpus through
 * the REAL pipeline and scores search / vector / rerank / citations / graph
 * / rejected-injection / refusal against gold labels — printing a scorecard
 * and persisting a dated report. Run it on demand or at a milestone close.
 *
 *   php artisan kb:benchmark                # LIVE: real embeddings + DB
 *   php artisan kb:benchmark --stub         # deterministic, no key/provider
 *   php artisan kb:benchmark --gate         # exit non-zero if below thresholds
 *
 * LIVE mode needs a configured embeddings provider (e.g. OpenRouter) and a
 * Postgres + pgvector connection; --stub runs anywhere (SQLite + PHP cosine).
 */
final class RunBenchmarkCommand extends Command
{
    protected $signature = 'kb:benchmark
        {--stub : Use deterministic stub embeddings (no API key / provider)}
        {--with-answers : Score answer-faithfulness via REAL chat + embeddings calls (LIVE even under --stub, which only stubs the retrieval embeddings; needs a configured chat+embeddings provider)}
        {--gate : Exit non-zero when aggregate metrics miss the thresholds}
        {--project=benchmark : Project key to ingest the corpus under}
        {--k=5 : Cut-off k for nDCG@k / precision@k}
        {--corpus= : Override the corpus directory}
        {--queries= : Override the labelled queries YAML path}';

    protected $description = 'Run the retrieval-quality benchmark (nDCG/MRR/precision/citation/refusal/graph).';

    public function handle(): int
    {
        // R30: every ingest and search call inside BenchmarkRunner operates on
        // tenant-aware tables; the tenant must be set before any DB work starts.
        app(TenantContext::class)->set(config('kb.default_tenant', 'default'));

        if ($this->option('stub')) {
            $this->laravel->instance(EmbeddingCacheService::class, new StubEmbeddingCache());
            $this->warn('Running with DETERMINISTIC STUB embeddings (no semantic quality — wiring/ranking only).');
        }

        $corpus = (string) ($this->option('corpus') ?: base_path('resources/benchmark/corpus'));
        $queries = (string) ($this->option('queries') ?: base_path('resources/benchmark/queries.yaml'));

        if (! is_dir($corpus) || ! is_file($queries)) {
            $this->error("Corpus dir or queries file not found:\n  corpus:  {$corpus}\n  queries: {$queries}");

            return self::FAILURE;
        }

        $card = $this->laravel->make(BenchmarkRunner::class)->run(
            corpusDir: $corpus,
            queriesFile: $queries,
            projectKey: (string) $this->option('project'),
            k: max(1, (int) $this->option('k')),
            withAnswers: (bool) $this->option('with-answers'),
        );

        $this->renderScorecard($card);
        $reportPath = $this->persist($card);
        $this->line("\nReport: {$reportPath}");

        if ($this->option('gate') && ! $card['passed']) {
            $this->error('Benchmark BELOW enterprise thresholds — gate failed.');

            return self::FAILURE;
        }

        $this->info($card['passed'] ? 'Benchmark PASSED enterprise thresholds.' : 'Benchmark complete (below thresholds; not gated).');

        return self::SUCCESS;
    }

    /** @param  array<string,mixed>  $card */
    private function renderScorecard(array $card): void
    {
        $this->line("\n<info>Retrieval-quality benchmark</info> — project={$card['project']} k={$card['k']} "
            ."corpus={$card['corpus_count']} queries={$card['query_count']}");

        $this->table(
            ['query', 'refuse', 'nDCG', 'P@k', 'RR', 'cite', 'graph', 'rej', 'top'],
            array_map(static function (array $r): array {
                $fmt = static fn ($v) => $v === null ? '—' : (is_float($v) ? number_format($v, 3) : (string) $v);
                $bool = static fn ($v) => $v === null ? '—' : ($v ? 'ok' : 'MISS');

                return [
                    $r['id'],
                    $r['expect_refusal'] ? ($r['refusal_correct'] ? 'ok' : 'MISS') : ($r['refused'] ? 'FALSE-REF' : '-'),
                    $fmt($r['ndcg']),
                    $fmt($r['precision']),
                    $fmt($r['rr']),
                    $bool($r['citation_ok']),
                    $r['related_hit'] === null ? '—' : "{$r['related_hit']}/{$r['related_expected']}",
                    $r['rejected_hit'] === null ? '—' : "{$r['rejected_hit']}/{$r['rejected_expected']}",
                    (string) ($r['top'] ?? '—'),
                ];
            }, $card['queries']),
        );

        $agg = $card['aggregate'];
        $thr = $card['thresholds'];
        $mark = static fn (string $key) => isset($thr[$key]) ? ($agg[$key] >= $thr[$key] ? ' ✅' : ' ❌ (<'.$thr[$key].')') : '';
        $this->line('<comment>Aggregate</comment>');
        $this->line('  nDCG@'.$card['k'].'        : '.number_format($agg['ndcg_at_k'], 4).$mark('ndcg_at_k'));
        $this->line('  MRR              : '.number_format($agg['mrr'], 4).$mark('mrr'));
        $this->line('  precision@'.$card['k'].'   : '.number_format($agg['precision_at_k'], 4));
        $this->line('  citation-prec.   : '.number_format($agg['citation_precision'], 4).$mark('citation_precision'));
        $this->line('  refusal-accuracy : '.number_format($agg['refusal_accuracy'], 4).$mark('refusal_accuracy'));
        $this->line('  graph-recall     : '.number_format($agg['graph_recall'], 4));
        $this->line('  rejected-recall  : '.number_format($agg['rejected_recall'], 4));
        // Show whenever --with-answers actually ran (any query carries a
        // non-null faithfulness), NOT only when the mean is > 0 — a run where
        // every answer refused/anti-correlated has a legitimate 0.0 mean that
        // must still be reported, otherwise the metric silently vanishes.
        $scored = array_filter($card['queries'], static fn (array $r): bool => ($r['faithfulness'] ?? null) !== null);
        if ($scored !== []) {
            $this->line('  answer-faithful. : '.number_format($agg['answer_faithfulness'], 4).' (real LLM answers)');
        }
    }

    /** @param  array<string,mixed>  $card @return string absolute-ish report path */
    private function persist(array $card): string
    {
        $stamp = now()->format('Y-m-d_His');
        $dir = 'kb-benchmark';
        $disk = Storage::disk('local');

        if ($disk->put("{$dir}/{$stamp}.json", json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException("Failed to write benchmark JSON report to [{$dir}/{$stamp}.json].");
        }
        if ($disk->put("{$dir}/{$stamp}.md", $this->markdownReport($card)) === false) {
            throw new \RuntimeException("Failed to write benchmark markdown report to [{$dir}/{$stamp}.md].");
        }

        return storage_path("app/{$dir}/{$stamp}.md");
    }

    /** @param  array<string,mixed>  $card */
    private function markdownReport(array $card): string
    {
        $agg = $card['aggregate'];
        $md = "# Retrieval-quality benchmark — {$card['project']}\n\n";
        $md .= '- when: '.now()->toIso8601String()."\n";
        $md .= "- k: {$card['k']}  corpus: {$card['corpus_count']}  queries: {$card['query_count']}\n";
        $md .= '- PASSED: '.($card['passed'] ? 'yes' : 'NO')."\n\n## Aggregate\n\n";
        foreach ($agg as $key => $val) {
            $md .= "- {$key}: ".number_format((float) $val, 4)."\n";
        }
        $md .= "\n## Per-query\n\n| id | refuse-ok | nDCG | P@k | RR | cite | graph | rej | top |\n";
        $md .= "|---|---|---|---|---|---|---|---|---|\n";
        foreach ($card['queries'] as $r) {
            $f = static fn ($v) => $v === null ? '—' : (is_float($v) ? number_format($v, 3) : (is_bool($v) ? ($v ? 'ok' : 'MISS') : (string) $v));
            $md .= "| {$r['id']} | ".($r['refusal_correct'] ? 'ok' : 'MISS')." | {$f($r['ndcg'])} | {$f($r['precision'])} | {$f($r['rr'])} "
                .'| '.$f($r['citation_ok']).' | '
                .($r['related_hit'] === null ? '—' : "{$r['related_hit']}/{$r['related_expected']}").' | '
                .($r['rejected_hit'] === null ? '—' : "{$r['rejected_hit']}/{$r['rejected_expected']}").' | '
                .($r['top'] ?? '—')." |\n";
        }

        return $md;
    }
}
