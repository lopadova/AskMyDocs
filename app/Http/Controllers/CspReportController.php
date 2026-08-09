<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collects browser CSP violation reports (csp-report-collection).
 *
 * The endpoint is inert (404) when no `report_uri` is configured, so a
 * deployment that has not opted into report collection exposes nothing
 * (R43 OFF path). When active it accepts a bounded body, logs a compact,
 * control-character-safe summary and answers 204 — it never reflects the
 * payload and never performs a side effect from report contents.
 */
class CspReportController extends Controller
{
    private const MAX_BODY_BYTES = 16_384;
    private const MAX_FIELD_LEN = 512;

    public function store(Request $request): Response
    {
        $reportUri = config('security-headers.csp.report_uri');
        if (! is_string($reportUri) || $reportUri === '') {
            abort(404);
        }

        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            // Oversized report — accept-and-drop, never parse.
            return response()->noContent();
        }

        $decoded = json_decode($raw, true);
        $report = is_array($decoded) ? ($decoded['csp-report'] ?? $decoded) : [];

        Log::channel(config('logging.default'))->warning('csp.violation', [
            'blocked_uri' => $this->field($report['blocked-uri'] ?? null),
            'violated_directive' => $this->field($report['violated-directive'] ?? null),
            'document_uri' => $this->field($report['document-uri'] ?? null),
        ]);

        return response()->noContent();
    }

    /**
     * Bound + strip control characters so a crafted report cannot inject log
     * lines or blow up the log record.
     */
    private function field(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $clean = preg_replace('/[[:cntrl:]]+/', ' ', $value) ?? '';

        return mb_substr(trim($clean), 0, self::MAX_FIELD_LEN);
    }
}
