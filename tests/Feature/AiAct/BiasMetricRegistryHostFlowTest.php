<?php

declare(strict_types=1);

namespace Tests\Feature\AiAct;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AiActCompliance\BiasMonitoring\Metrics\CalibrationMetric;
use Padosoft\AiActCompliance\BiasMonitoring\Metrics\DemographicParityMetric;
use Padosoft\AiActCompliance\BiasMonitoring\Metrics\EqualizedOddsMetric;
use Padosoft\AiActCompliance\BiasMonitoring\Services\MetricRegistry;
use Tests\TestCase;

/**
 * v6.1.1 — host-side proof that the v1.2 pluggable
 * `CohortParityMetric` registry is reachable through AskMyDocs's
 * container and that the three reference metrics resolve correctly.
 *
 * Sister-package has 50+ tests for the registry + metrics in isolation;
 * this covers the integration boundary: the registry is auto-seeded
 * from `config/ai-act-compliance.php` in the package's SP `boot()`
 * (R23: FQCN + supports() validated at boot), so a fresh AskMyDocs
 * install can resolve `'demographic_parity'`, `'equalized_odds'`, and
 * `'calibration'` straight out of the container without any host
 * registration.
 */
class BiasMetricRegistryHostFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_reference_metrics_are_auto_registered(): void
    {
        $registry = app(MetricRegistry::class);

        $this->assertTrue($registry->has('demographic_parity'), 'v1.2 default metric must be auto-registered');
        $this->assertTrue($registry->has('equalized_odds'));
        $this->assertTrue($registry->has('calibration'));
    }

    public function test_each_reference_metric_resolves_to_its_concrete_class(): void
    {
        $registry = app(MetricRegistry::class);

        $this->assertInstanceOf(DemographicParityMetric::class, $registry->resolve('demographic_parity'));
        $this->assertInstanceOf(EqualizedOddsMetric::class, $registry->resolve('equalized_odds'));
        $this->assertInstanceOf(CalibrationMetric::class, $registry->resolve('calibration'));
    }
}
