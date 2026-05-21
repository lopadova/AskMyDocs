<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\ComplianceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ComplianceReportController
{
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
}
