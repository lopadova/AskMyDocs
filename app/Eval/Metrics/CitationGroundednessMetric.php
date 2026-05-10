<?php

declare(strict_types=1);

namespace App\Eval\Metrics;

use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Throwable;

/**
 * Custom AskMyDocs metric — strict citation matching.
 *
 * Why we need this on TOP of the package's `citation-groundedness`:
 *   The built-in metric scores citation TOKENS (e.g. `[policy:refunds]`
 *   substrings) inside the answer body. AskMyDocs returns
 *   structured citations OUTSIDE the answer body — they live in the
 *   JSON `citations[].source_path` field consumed by the FE. This
 *   metric asserts:
 *
 *     1. Every `expected_citations[i]` from the dataset YAML appears
 *        as a `citations[].source_path` in the SUT payload.
 *     2. Every cited `source_path` resolves to an actual seeded
 *        KnowledgeDocument under the sample's project_key (catches
 *        phantom citations — paths the model fabricated).
 *     3. When `expected_citations` is empty (refusal samples), the
 *        actual `citations` list MUST also be empty.
 *
 * Scoring (in [0, 1]):
 *   - All three checks pass → 1.0
 *   - One or more expected citations missing → proportional partial
 *     credit: hits / max(hits + misses, 1)
 *   - Phantom citations present → cap the score at 0.5
 *     (catastrophic but not zeroed if recall is high)
 *   - Refusal sample with non-empty actual citations → 0.0
 *     (the model must NOT fabricate sources when refusing)
 *
 * R30: tenant-scoped queries via forTenant(TenantContext::current()).
 */
final class CitationGroundednessMetric implements Metric
{
    public function name(): string
    {
        return 'citation-groundedness-strict';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        $payload = $this->decodePayload($actualOutput);
        if ($payload === null) {
            return new MetricScore(0.0, ['reason' => 'unparseable_payload']);
        }

        // The YamlDatasetLoader strips top-level sample fields it
        // doesn't recognise, so the AskMyDocs golden YAMLs carry
        // `expected_citations` under `metadata.expected_citations`.
        $expected = array_values(array_filter(
            (array) ($sample->metadata['expected_citations'] ?? []),
            static fn ($p): bool => is_string($p) && $p !== '',
        ));

        $actualCitations = (array) ($payload['citations'] ?? []);
        $actualPaths = [];
        foreach ($actualCitations as $citation) {
            if (! is_array($citation)) {
                continue;
            }
            $path = $citation['source_path'] ?? null;
            if (is_string($path) && $path !== '') {
                $actualPaths[] = $path;
            }
        }
        $actualPaths = array_values(array_unique($actualPaths));

        // Refusal-shape contract: empty expected → must NOT fabricate.
        if ($expected === []) {
            if ($actualPaths === []) {
                return new MetricScore(1.0, ['reason' => 'refusal_clean']);
            }

            return new MetricScore(0.0, [
                'reason' => 'refusal_with_fabricated_citations',
                'fabricated_paths' => $actualPaths,
            ]);
        }

        // Recall: how many expected citations appeared.
        $expectedSet = array_flip($expected);
        $hits = 0;
        $misses = [];
        foreach ($expected as $path) {
            if (in_array($path, $actualPaths, true)) {
                $hits++;
                continue;
            }
            $misses[] = $path;
        }

        // Phantom guard: paths cited that aren't in the expected set
        // AND don't resolve to a real seeded document under this
        // project_key. A path that resolves to a real doc but isn't
        // in expected_citations is "extra context", not phantom — we
        // don't penalise for that (the reranker may surface a
        // related-but-correct doc that the dataset author didn't list).
        $projectKey = (string) ($payload['meta']['project_key'] ?? $sample->input['project_key'] ?? '');
        $phantomPaths = $this->detectPhantomPaths($projectKey, $actualPaths, array_keys($expectedSet));

        $denom = max(1, $hits + count($misses));
        $score = $hits / $denom;

        // Phantom penalty: cap score at 0.5 when a phantom is present.
        // High recall + one phantom should not look identical to high
        // recall + zero phantoms.
        if ($phantomPaths !== []) {
            $score = min($score, 0.5);
        }

        return new MetricScore($score, [
            'expected_count' => count($expected),
            'hits' => $hits,
            'misses' => $misses,
            'phantom_paths' => $phantomPaths,
            'actual_paths' => $actualPaths,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<string>  $actualPaths
     * @param  list<string>  $expectedPaths
     * @return list<string>
     */
    private function detectPhantomPaths(string $projectKey, array $actualPaths, array $expectedPaths): array
    {
        if ($projectKey === '' || $actualPaths === []) {
            return [];
        }

        $unexpected = array_values(array_diff($actualPaths, $expectedPaths));
        if ($unexpected === []) {
            return [];
        }

        $tenantId = app(TenantContext::class)->current();

        $resolved = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->whereIn('source_path', $unexpected)
            ->pluck('source_path')
            ->all();

        return array_values(array_diff($unexpected, $resolved));
    }
}
