<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Metrics;

use App\Services\Kb\Metrics\PackageMetricAdapter;
use Illuminate\Config\Repository;
use Padosoft\EvalHarness\Metrics\RetrievalMrrMetric;
use Padosoft\EvalHarness\Metrics\RetrievalNdcgAtKMetric;
use PHPUnit\Framework\TestCase;

/**
 * v8.18/W2 — the adapter converts AskMyDocs's in-proc ranked ids + relevance
 * into the package's DatasetSample + actualOutput JSON and returns the float
 * score. Pure math, zero fakes.
 */
final class PackageMetricAdapterTest extends TestCase
{
    private function configRepo(): Repository
    {
        return new Repository([
            'eval-harness' => ['metrics' => ['retrieval' => ['default_k' => 5]]],
        ]);
    }

    private function adapter(): PackageMetricAdapter
    {
        return new PackageMetricAdapter($this->configRepo());
    }

    public function test_scores_mrr_from_ranked_ids_and_relevant_set(): void
    {
        $config = $this->configRepo();
        $score = (new PackageMetricAdapter($config))->scoreRanked(
            new RetrievalMrrMetric($config),
            ['a', 'b', 'c'],
            ['b'],
        );
        self::assertEqualsWithDelta(0.5, $score, 1e-9);
    }

    public function test_scores_ndcg_from_graded_gains(): void
    {
        $config = $this->configRepo();
        $score = (new PackageMetricAdapter($config))->scoreRankedWithGains(
            new RetrievalNdcgAtKMetric($config),
            ['a', 'b', 'c', 'd'],
            ['a' => 1.0, 'b' => 1.0, 'c' => 0.0, 'd' => 0.0],
            4,
        );
        self::assertEqualsWithDelta(1.0, $score, 1e-9);
    }
}
