<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use App\FinOps\OcrCallMeter;
use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use App\Support\Kb\SourceType;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * v8.36 / ADR 0029 §8 — the number shown BEFORE commit on the upload modal.
 *
 * For every staged item: would OCR run (image → yes, unless it is a
 * multi-frame TIFF the configured driver transcribes one frame of —
 * `multi_frame_image`; PDF → only when the text-layer probe finds no text
 * and, for a driver that refuses an unverified page count — remote, or
 * unable to bound its own work — only when the count is exact: an
 * unparseable PDF is `pages_uncountable`; anything else → no), how many
 * pages, and
 * pages × `kb.ocr.rate_per_page`. The estimate reads the staged bytes
 * through the staging disk — it never runs a driver, so it is cheap and
 * side-effect free. With OCR disabled every item reports `would_ocr=false`
 * and the total is zero (R43: the OFF path is honest, not absent).
 */
final class OcrCostEstimator
{
    public function __construct(
        private readonly OcrCallMeter $meter,
        private readonly PdfTextLayerProbe $probe,
        private readonly OcrDriverRegistry $registry,
        private readonly OcrService $ocr,
    ) {}

    /**
     * @return array{
     *   enabled: bool, driver: string, driver_available: bool, driver_error: ?string, metering: string, currency: string, rate_per_page: float,
     *   total_pages: int, total_cost: float,
     *   items: list<array{id: string, would_ocr: bool, pages: int, cost: float, reason: string, pages_exact: bool}>
     * }
     */
    public function forBatch(KbIngestBatch $batch, string $stagingDisk): array
    {
        $enabled = (bool) config('kb.ocr.enabled', false);
        $batch->loadMissing('items');
        $driverStatus = $this->driverStatus($enabled);
        // ADR 0029 §10 — `pages × rate` is the PerPage price; an Sdk driver
        // (vision-llm) is metered per token by the laravel/ai lifecycle hook
        // after the fact, so the estimate must not invent a page price for
        // it: cost stays 0 and `metering` says why.
        $sdkMetered = $driverStatus['metering'] === OcrMeteringMode::Sdk->value;

        $items = [];
        $totalPages = 0;
        $totalCost = 0.0;
        foreach ($batch->items as $item) {
            $row = $this->forItem($item, $stagingDisk, $enabled, $sdkMetered);
            $items[] = $row;
            if ($row['would_ocr']) {
                $totalPages += $row['pages'];
                $totalCost += $row['cost'];
            }
        }

        $base = $this->meter->estimate(0);

        return [
            'enabled' => $enabled,
            'driver' => (string) config('kb.ocr.driver', 'tesseract'),
            // R14 — the modal must never promise a run the registry will
            // refuse (remote driver with the knob off, binary missing).
            'driver_available' => $driverStatus['available'],
            'driver_error' => $driverStatus['error'],
            // per_page: total_cost = pages × rate_per_page; sdk: no page rate,
            // the provider meters tokens and FinOps records the real spend.
            'metering' => $driverStatus['metering'],
            'currency' => $base['currency'],
            'rate_per_page' => $sdkMetered ? 0.0 : $base['rate_per_page'],
            'total_pages' => $totalPages,
            'total_cost' => round($totalCost, 6),
            'items' => $items,
        ];
    }

    /**
     * @return array{available: bool, error: ?string, metering: string}
     */
    private function driverStatus(bool $enabled): array
    {
        if (! $enabled) {
            return ['available' => false, 'error' => null, 'metering' => OcrMeteringMode::PerPage->value];
        }
        try {
            $driver = $this->registry->configured();
            $metering = $driver->meteringMode()->value;
            $unavailable = $driver->unavailableReason();
            if ($unavailable !== null) {
                return ['available' => false, 'error' => sprintf('OCR driver "%s" is not available on this host: %s', $driver->name(), $unavailable), 'metering' => $metering];
            }

            return ['available' => true, 'error' => null, 'metering' => $metering];
        } catch (Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage(), 'metering' => OcrMeteringMode::PerPage->value];
        }
    }

    /**
     * @return array{id: string, would_ocr: bool, pages: int, cost: float, reason: string, pages_exact: bool}
     */
    private function forItem(KbIngestBatchItem $item, string $stagingDisk, bool $enabled, bool $sdkMetered = false): array
    {
        $id = (string) $item->id;
        if (! $enabled) {
            return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'ocr_disabled', 'pages_exact' => true];
        }

        $type = SourceType::tryFrom((string) $item->source_type) ?? SourceType::UNKNOWN;

        $maxBytes = max(1, (int) config('kb.ocr.max_bytes', 26214400));
        $maxPages = max(1, (int) config('kb.ocr.max_pages', 200));

        if ($type === SourceType::IMAGE) {
            if ((int) $item->size_bytes > $maxBytes) {
                return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'too_many_bytes', 'pages_exact' => true];
            }
            $stagingPath = (string) $item->staging_path;
            if ($stagingPath === '' || ! Storage::disk($stagingDisk)->exists($stagingPath)) {
                return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'staged_file_missing', 'pages_exact' => true];
            }
            // The SAME function the service enforces the cap with, on the
            // staged bytes: a multi-page TIFF is N pages whatever extension
            // it was uploaded under (the sniffer accepts any raster and the
            // batch row only stores the family MIME), so the modal can never
            // quote a run the ingest will refuse.
            $pages = $this->ocr->pageCountForBytes((string) $item->mime_type, (string) Storage::disk($stagingDisk)->get($stagingPath));
            if ($pages > $maxPages) {
                return ['id' => $id, 'would_ocr' => false, 'pages' => $pages, 'cost' => 0.0, 'reason' => 'too_many_pages', 'pages_exact' => true];
            }
            // The same refusal the service applies: a driver that OCRs one
            // frame per image never receives a multi-frame TIFF (R14 — the
            // modal says so before commit instead of quoting N pages).
            if ($pages > 1 && $this->registry->refusesMultiFrameImages((string) config('kb.ocr.driver', 'tesseract'))) {
                return ['id' => $id, 'would_ocr' => false, 'pages' => $pages, 'cost' => 0.0, 'reason' => 'multi_frame_image', 'pages_exact' => true];
            }

            return $this->priced($id, $pages, 'image', true, $sdkMetered);
        }

        if ($type !== SourceType::PDF) {
            return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'not_ocr_able', 'pages_exact' => true];
        }

        $stagingPath = (string) $item->staging_path;
        if ($stagingPath === '' || ! Storage::disk($stagingDisk)->exists($stagingPath)) {
            return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'staged_file_missing', 'pages_exact' => true];
        }

        $bytes = (string) Storage::disk($stagingDisk)->get($stagingPath);
        if (strlen($bytes) > $maxBytes) {
            return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'too_many_bytes', 'pages_exact' => true];
        }
        // One parse: the probe reports the total page count too (a floor,
        // `pages_exact = false`, when the parser cannot read the file).
        $probe = $this->probe->probe($bytes);
        if ($probe['verdict'] === PdfTextLayerProbe::PRESENT) {
            return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'text_layer_present', 'pages_exact' => true];
        }
        $pages = max(1, (int) $probe['pages_total']);
        $exact = (bool) $probe['pages_exact'];
        // The same refusal the service applies (ADR 0029 §4): a floor is not
        // a cap input, so an unparseable PDF runs only on a local driver that
        // bounds its own work — and the modal says so before commit (R14).
        if (! $exact && $this->registry->refusesUnverifiedPageCount((string) config('kb.ocr.driver', 'tesseract'))) {
            return ['id' => $id, 'would_ocr' => false, 'pages' => $pages, 'cost' => 0.0, 'reason' => 'pages_uncountable', 'pages_exact' => false];
        }
        if ($pages > $maxPages) {
            return ['id' => $id, 'would_ocr' => false, 'pages' => $pages, 'cost' => 0.0, 'reason' => 'too_many_pages', 'pages_exact' => $exact];
        }

        return $this->priced($id, $pages, $probe['verdict'] === PdfTextLayerProbe::MIXED ? 'mixed_pdf' : 'scanned_pdf', $exact, $sdkMetered);
    }

    /**
     * @return array{id: string, would_ocr: bool, pages: int, cost: float, reason: string, pages_exact: bool}
     */
    private function priced(string $id, int $pages, string $reason, bool $exact = true, bool $sdkMetered = false): array
    {
        $estimate = $this->meter->estimate($pages);

        return ['id' => $id, 'would_ocr' => true, 'pages' => $estimate['pages'], 'cost' => $sdkMetered ? 0.0 : $estimate['cost'], 'reason' => $reason, 'pages_exact' => $exact];
    }

}
