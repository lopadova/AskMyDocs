<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\ComplianceReport;
use App\Services\Compliance\ComplianceReportGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

final class ComplianceReportController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) $request->query('tenant_id', '');

        $query = ComplianceReport::query()
            ->orderByDesc('generated_at')
            ->orderByDesc('id');

        if ($tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }

        $rows = $query
            ->limit(100)
            ->get()
            ->map(fn (ComplianceReport $report): array => [
                'id' => $report->id,
                'tenant_id' => $report->tenant_id,
                'period_start' => $report->period_start?->toDateString(),
                'period_end' => $report->period_end?->toDateString(),
                'hash_sha256' => $report->hash_sha256,
                'hash_hmac' => $report->hash_hmac,
                'generated_at' => $report->generated_at?->toISOString(),
                'generated_by' => $report->generated_by,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function store(Request $request, ComplianceReportGenerator $generator): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'string', 'max:255'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);

        $report = $generator->generate(
            (string) $validated['tenant_id'],
            (string) $validated['period_start'],
            (string) $validated['period_end'],
            auth()->id(),
        );

        return response()->json([
            'data' => [
                'id' => $report->id,
                'tenant_id' => $report->tenant_id,
                'period_start' => $report->period_start?->toDateString(),
                'period_end' => $report->period_end?->toDateString(),
                'hash_sha256' => $report->hash_sha256,
                'hash_hmac' => $report->hash_hmac,
                'generated_at' => $report->generated_at?->toISOString(),
                'generated_by' => $report->generated_by,
            ],
        ], Response::HTTP_CREATED);
    }

    public function verify(ComplianceReport $report): JsonResponse
    {
        try {
            $payloadJson = json_encode(
                $report->payload_json,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to encode compliance report payload', 0, $e);
        }

        $expectedSha256 = hash('sha256', $payloadJson);
        $expectedHmac = hash_hmac(
            'sha256',
            $payloadJson.$report->tenant_id.$report->period_start?->toDateString().$report->period_end?->toDateString(),
            $this->hmacSecret(),
        );

        $shaValid = hash_equals((string) $report->hash_sha256, $expectedSha256);
        $hmacValid = hash_equals((string) $report->hash_hmac, $expectedHmac);

        return response()->json([
            'valid' => $shaValid && $hmacValid,
            'expected_hash' => [
                'sha256' => $expectedSha256,
                'hmac' => $expectedHmac,
            ],
            'actual_hash' => [
                'sha256' => (string) $report->hash_sha256,
                'hmac' => (string) $report->hash_hmac,
            ],
        ]);
    }

    public function downloadJson(ComplianceReport $report): Response|JsonResponse
    {
        $filename = sprintf(
            'compliance-report-%s-%s-%s.json',
            $report->tenant_id,
            $report->period_start?->toDateString() ?? 'start',
            $report->period_end?->toDateString() ?? 'end',
        );

        return response()->json($report->payload_json)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
    }

    public function downloadPdf(ComplianceReport $report): Response|JsonResponse
    {
        if (! class_exists('Spatie\\Browsershot\\Browsershot')) {
            return response()->json([
                'message' => 'PDF rendering failed.',
                'error' => 'spatie/browsershot package is not installed.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $html = view('admin.compliance.report', ['report' => $report])->render();
            $pdfBytes = \Spatie\Browsershot\Browsershot::html($html)
                ->format('A4')
                ->margins(10, 10, 12, 10)
                ->showBackground()
                ->pdf();
        } catch (Throwable $e) {
            Log::error('Compliance report PDF export failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'PDF rendering failed.',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (! is_string($pdfBytes) || $pdfBytes === '') {
            return response()->json([
                'message' => 'PDF rendering failed.',
                'error' => 'Browsershot returned an empty payload.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $filename = sprintf(
            'compliance-report-%s-%s-%s.pdf',
            $report->tenant_id,
            $report->period_start?->toDateString() ?? 'start',
            $report->period_end?->toDateString() ?? 'end',
        );

        return response($pdfBytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdfBytes),
        ]);
    }

    private function hmacSecret(): string
    {
        $secret = (string) config('askmydocs.compliance.hmac_secret', '');
        if ($secret === '') {
            throw new RuntimeException('askmydocs.compliance.hmac_secret is required');
        }

        return $secret;
    }
}
