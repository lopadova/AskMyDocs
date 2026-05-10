<?php

declare(strict_types=1);

namespace App\Pii\Inspectors;

use Padosoft\PiiRedactor\Admin\RedactorAdminInspector;
use Padosoft\PiiRedactor\Reports\DetectionReport;
use Padosoft\PiiRedactor\Reports\DetectionReportFormatter;

/**
 * v4.3/W1 sub-PR 4.5 — B1 + B2 — Thin host-app adapter that combines
 * v1.2's `RedactorAdminInspector` (per-detector counts + pack snapshot)
 * with `DetectionReportFormatter` (sample-masking) for the AskMyDocs
 * admin dashboard.
 *
 * The package classes are designed to be standalone-agnostic; this
 * adapter encodes AskMyDocs's specific shape requirement: the dashboard
 * wants `{config_snapshot, detection_summary}` in one payload so a
 * single `/api/admin/metrics/health` request can populate the PII
 * panel without a second round-trip.
 *
 * Sample masking is FORCED (`includeRawSamples=false`) — even the admin
 * dashboard never sees raw PII; it sees `[email]`, `[iban]`, etc. via
 * `DetectionReportFormatter::safeArray()`. Operators with the
 * `pii.detokenize` permission round-trip through the dedicated
 * detokenize endpoint; the dashboard does NOT bypass that gate.
 */
final class InsightsRedactionFormatter
{
    public function __construct(
        private readonly RedactorAdminInspector $inspector,
        private readonly DetectionReportFormatter $formatter,
    ) {}

    /**
     * @return array{
     *     config_snapshot: array<string, mixed>,
     *     detection_summary: array{total: int, counts: array<string, int>, samples: array<string, list<string>>},
     * }
     */
    public function dashboardPayload(DetectionReport $report): array
    {
        return [
            'config_snapshot' => $this->inspector->snapshot(),
            // Force sample-masking: the dashboard NEVER sees raw PII.
            'detection_summary' => $this->formatter->safeArray($report, includeRawSamples: false),
        ];
    }

    /**
     * Config-only snapshot for the /metrics/health probe (no scan).
     *
     * @return array<string, mixed>
     */
    public function configSnapshot(): array
    {
        return $this->inspector->snapshot();
    }
}
