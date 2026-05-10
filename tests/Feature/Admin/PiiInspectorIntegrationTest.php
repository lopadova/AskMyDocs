<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Pii\Inspectors\InsightsRedactionFormatter;
use App\Services\Admin\HealthCheckService;
use Padosoft\PiiRedactor\Reports\DetectionReport;
use Tests\TestCase;

/**
 * v4.3/W1 sub-PR 4.5 — B1 + B2 + B3 — admin-readiness inspector wiring
 * smoke test. Ensures the host-app adapter classes resolve from the
 * container and emit the documented shape.
 */
final class PiiInspectorIntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    public function test_insights_redaction_formatter_returns_expected_shape(): void
    {
        $formatter = app(InsightsRedactionFormatter::class);
        $payload = $formatter->dashboardPayload(new DetectionReport([]));

        $this->assertArrayHasKey('config_snapshot', $payload);
        $this->assertArrayHasKey('detection_summary', $payload);
        $this->assertArrayHasKey('total', $payload['detection_summary']);
        $this->assertArrayHasKey('counts', $payload['detection_summary']);
        $this->assertArrayHasKey('samples', $payload['detection_summary']);

        $snapshot = $payload['config_snapshot'];
        $this->assertArrayHasKey('enabled', $snapshot);
        $this->assertArrayHasKey('default_strategy', $snapshot);
        $this->assertArrayHasKey('detectors', $snapshot);
        $this->assertArrayHasKey('packs', $snapshot);
    }

    public function test_health_check_service_pii_redactor_report_disabled_shape(): void
    {
        config()->set('kb.pii_redactor.enabled', false);

        $service = app(HealthCheckService::class);
        $report = $service->piiRedactorReport();

        $this->assertSame('disabled', $report['status']);
        $this->assertFalse($report['enabled']);
        $this->assertSame(0, $report['total_rules']);
        $this->assertSame([], $report['packs']);
    }

    public function test_health_check_service_pii_redactor_report_enabled_no_packs_is_ok(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('pii-redactor.custom_rules.packs', []);

        $service = app(HealthCheckService::class);
        $report = $service->piiRedactorReport();

        $this->assertSame('ok', $report['status']);
        $this->assertTrue($report['enabled']);
        $this->assertSame(0, $report['total_rules']);
    }
}
