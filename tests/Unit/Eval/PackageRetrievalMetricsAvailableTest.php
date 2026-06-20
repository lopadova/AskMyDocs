<?php

declare(strict_types=1);

namespace Tests\Unit\Eval;

use Padosoft\EvalHarness\Metrics\AnswerContainmentAtKMetric;
use Padosoft\EvalHarness\Metrics\Retrieval\RankedRetrieval;
use Padosoft\EvalHarness\Metrics\RetrievalHitAtKMetric;
use Padosoft\EvalHarness\Metrics\RetrievalMrrMetric;
use Padosoft\EvalHarness\Metrics\RetrievalNdcgAtKMetric;
use Padosoft\EvalHarness\Metrics\RetrievalRecallAtKMetric;
use PHPUnit\Framework\TestCase;

/**
 * v8.18/W2 Task 0 — dependency-floor guard. The retrieval-metric delegation
 * (PackageMetricAdapter + RetrievalQualityMetrics) requires
 * `padosoft/eval-harness` >= 1.3.0, which ships the ranking metrics this app
 * delegates to. This test fails under `^1.2.0` (classes absent) and passes once
 * the floor is bumped + installed.
 */
final class PackageRetrievalMetricsAvailableTest extends TestCase
{
    public function test_package_ships_the_retrieval_metric_classes(): void
    {
        foreach ([
            RankedRetrieval::class,
            RetrievalMrrMetric::class,
            RetrievalNdcgAtKMetric::class,
            RetrievalHitAtKMetric::class,
            RetrievalRecallAtKMetric::class,
            AnswerContainmentAtKMetric::class,
        ] as $fqcn) {
            self::assertTrue(class_exists($fqcn), "Missing package class {$fqcn}");
        }
    }
}
