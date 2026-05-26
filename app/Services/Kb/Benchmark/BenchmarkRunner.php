<?php

declare(strict_types=1);

namespace App\Services\Kb\Benchmark;

use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Services\Kb\Retrieval\RetrievalGrounding;
use App\Services\Kb\Metrics\RetrievalQualityMetrics as Metrics;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

/**
 * Orchestrates the retrieval-quality benchmark: ingest the labelled corpus
 * through the REAL pipeline, run each labelled query through the SAME
 * searchWithContext() path the chat uses, and score the results against the
 * gold labels with {@see Metrics}.
 *
 * Driver/embedder-agnostic: the caller decides whether the bound
 * EmbeddingCacheService is real (LIVE) or a deterministic stub, and which DB
 * connection is active. The runner just measures whatever pipeline is wired.
 *
 * Returns a structured scorecard (per-query + aggregate) that the command
 * prints and persists; the same shape can feed a dashboard or a CI gate.
 */
final class BenchmarkRunner
{
    /** Doc-types to ingest, keyed filename => mime. */
    private const MIME = [
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
    ];

    public function __construct(
        private readonly DocumentIngestor $ingestor,
        private readonly KbSearchService $search,
        private readonly ChatRetrievalService $retrieval,
    ) {}

    /**
     * @return array{
     *   project: string, k: int, corpus_count: int, query_count: int,
     *   queries: list<array<string,mixed>>,
     *   aggregate: array{ndcg_at_k: float, mrr: float, precision_at_k: float,
     *     citation_precision: float, refusal_accuracy: float, graph_recall: float,
     *     rejected_recall: float},
     *   thresholds: array<string,float>, passed: bool
     * }
     */
    public function run(string $corpusDir, string $queriesFile, string $projectKey, int $k = 5): array
    {
        $corpusCount = $this->ingestCorpus($corpusDir, $projectKey);
        $queries = $this->loadQueries($queriesFile);

        $rows = [];
        foreach ($queries as $q) {
            $rows[] = $this->scoreQuery($q, $projectKey, $k);
        }

        $aggregate = $this->aggregate($rows, $k);
        $thresholds = $this->thresholds();

        return [
            'project' => $projectKey,
            'k' => $k,
            'corpus_count' => $corpusCount,
            'query_count' => count($rows),
            'queries' => $rows,
            'aggregate' => $aggregate,
            'thresholds' => $thresholds,
            'passed' => $this->meetsThresholds($aggregate, $thresholds),
        ];
    }

    private function ingestCorpus(string $corpusDir, string $projectKey): int
    {
        $count = 0;
        foreach (glob(rtrim($corpusDir, '/').'/*') ?: [] as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = self::MIME[$ext] ?? null;
            if ($mime === null) {
                continue;
            }
            $name = basename($path);
            $source = new SourceDocument(
                sourcePath: $name,
                mimeType: $mime,
                bytes: (string) file_get_contents($path),
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: [],
            );
            $this->ingestor->ingest($projectKey, $source, pathinfo($name, PATHINFO_FILENAME));
            $count++;
        }

        return $count;
    }

    /** @return list<array<string,mixed>> */
    private function loadQueries(string $queriesFile): array
    {
        $parsed = Yaml::parseFile($queriesFile);

        return array_values($parsed['queries'] ?? []);
    }

    /**
     * @param  array<string,mixed>  $q
     * @return array<string,mixed>
     */
    private function scoreQuery(array $q, string $projectKey, int $k): array
    {
        $result = $this->search->searchWithContext(
            query: (string) $q['query'],
            projectKey: $projectKey,
            limit: $k,
            minSimilarity: (float) config('kb.default_min_similarity', 0.30),
        );

        $rankedDocKeys = $this->rankedDocKeys($result->primary);
        $relevance = (array) ($q['relevance'] ?? []);
        $relevantKeys = array_keys($relevance);

        $expectRefusal = (bool) ($q['expect_refusal'] ?? false);
        $refused = RetrievalGrounding::shouldRefuse($result->primary);

        $citationKeys = $this->docKeys(
            collect($this->retrieval->buildCitations($result))->pluck('source_path'),
        );
        $expandedKeys = $this->docKeys($result->expanded->pluck('document.source_path'));
        $rejectedKeys = $this->docKeys($result->rejected->pluck('document.source_path'));

        $expectCitation = $q['expect_citation'] ?? null;
        $expectRelated = (array) ($q['expect_related'] ?? []);
        $expectRejected = (array) ($q['expect_rejected'] ?? []);

        return [
            'id' => (string) ($q['id'] ?? '?'),
            'query' => (string) $q['query'],
            'expect_refusal' => $expectRefusal,
            'refused' => $refused,
            'refusal_correct' => $refused === $expectRefusal,
            'ndcg' => $expectRefusal ? null : round(Metrics::ndcgAtK($rankedDocKeys, $relevance, $k), 4),
            'precision' => $expectRefusal ? null : round(Metrics::precisionAtK($rankedDocKeys, $relevantKeys, $k), 4),
            'rr' => $expectRefusal ? null : round(Metrics::reciprocalRank($rankedDocKeys, $relevantKeys), 4),
            'citation_ok' => $expectCitation === null ? null : in_array($expectCitation, $citationKeys, true),
            // Related context counts whether it surfaced in primary OR via
            // graph expansion — what matters is that the LLM SAW it. (With a
            // small corpus the partner is often already in primary, so
            // expansion correctly doesn't duplicate it.)
            'related_hit' => $expectRelated === [] ? null : count(array_intersect($expectRelated, array_merge($rankedDocKeys, $expandedKeys))),
            'related_expected' => count($expectRelated),
            'rejected_hit' => $expectRejected === [] ? null : count(array_intersect($expectRejected, $rejectedKeys)),
            'rejected_expected' => count($expectRejected),
            'top' => $rankedDocKeys[0] ?? null,
        ];
    }

    /**
     * Ranked list of UNIQUE doc-keys from the primary chunk list, best-first.
     *
     * @param  Collection<int, mixed>  $primary
     * @return list<string>
     */
    private function rankedDocKeys(Collection $primary): array
    {
        $seen = [];
        $out = [];
        foreach ($primary as $chunk) {
            $key = $this->docKey((string) data_get($chunk, 'document.source_path', ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $key;
        }

        return $out;
    }

    /** @param  Collection<int, mixed>  $paths @return list<string> */
    private function docKeys(Collection $paths): array
    {
        return $paths
            ->map(fn ($p) => $this->docKey((string) $p))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** source_path → basename without extension (the label key). */
    private function docKey(string $sourcePath): string
    {
        return $sourcePath === '' ? '' : pathinfo($sourcePath, PATHINFO_FILENAME);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,float>
     */
    private function aggregate(array $rows, int $k): array
    {
        $answerable = array_values(array_filter($rows, fn ($r) => ! $r['expect_refusal']));

        $mean = static function (array $rows, string $key): float {
            $vals = array_values(array_filter(array_map(fn ($r) => $r[$key], $rows), fn ($v) => $v !== null));

            return $vals === [] ? 0.0 : array_sum($vals) / count($vals);
        };

        $ratio = static function (array $rows, callable $applies, callable $ok): float {
            $subset = array_values(array_filter($rows, $applies));

            return $subset === [] ? 1.0 : count(array_filter($subset, $ok)) / count($subset);
        };

        return [
            'ndcg_at_k' => round($mean($answerable, 'ndcg'), 4),
            'mrr' => round($mean($answerable, 'rr'), 4),
            'precision_at_k' => round($mean($answerable, 'precision'), 4),
            'citation_precision' => round($ratio(
                $rows,
                fn ($r) => $r['citation_ok'] !== null,
                fn ($r) => $r['citation_ok'] === true,
            ), 4),
            'refusal_accuracy' => round($ratio(
                $rows,
                fn ($r) => true,
                fn ($r) => $r['refusal_correct'] === true,
            ), 4),
            'graph_recall' => round($ratio(
                $rows,
                fn ($r) => $r['related_hit'] !== null,
                fn ($r) => $r['related_hit'] >= $r['related_expected'] && $r['related_expected'] > 0,
            ), 4),
            'rejected_recall' => round($ratio(
                $rows,
                fn ($r) => $r['rejected_hit'] !== null,
                fn ($r) => $r['rejected_hit'] > 0,
            ), 4),
        ];
    }

    /** @return array<string,float> */
    private function thresholds(): array
    {
        return [
            'ndcg_at_k' => (float) config('kb.benchmark.threshold_ndcg', 0.80),
            'mrr' => (float) config('kb.benchmark.threshold_mrr', 0.85),
            'citation_precision' => (float) config('kb.benchmark.threshold_citation_precision', 0.90),
            'refusal_accuracy' => (float) config('kb.benchmark.threshold_refusal_accuracy', 0.95),
        ];
    }

    /**
     * @param  array<string,float>  $aggregate
     * @param  array<string,float>  $thresholds
     */
    private function meetsThresholds(array $aggregate, array $thresholds): bool
    {
        foreach ($thresholds as $metric => $min) {
            if (($aggregate[$metric] ?? 0.0) < $min) {
                return false;
            }
        }

        return true;
    }
}
