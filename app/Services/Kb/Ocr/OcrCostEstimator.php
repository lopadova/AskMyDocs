<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use App\FinOps\OcrCallMeter;
use App\Models\KbIngestBatch;
use App\Models\KbIngestBatchItem;
use App\Support\Kb\FileTypeSniffer;
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
        private readonly PdfTextFallback $pdfTextFallback,
    ) {}

    /**
     * @return array{
     *   enabled: bool, driver: string, driver_available: bool, driver_error: ?string, metering: string, currency: string, rate_per_page: float,
     *   total_pages: int, total_cost: float,
     *   items: list<array{id: string, would_ocr: bool, pages: int, cost: float, reason: string, pages_exact: bool, driver_available: bool}>
     * }
     */
    public function forBatch(KbIngestBatch $batch, string $stagingDisk): array
    {
        $enabled = (bool) config('kb.ocr.enabled', false);
        $batch->loadMissing('items');
        // The preflight asks the driver about each KIND of input: a PDF
        // needs the rasteriser's Poppler binaries, an image does not — so a
        // mixed batch reports the PDF as blocked and the image as runnable,
        // exactly as `OcrService::convert()` will treat each (R14).
        $driverStatus = $this->driverStatus($enabled);
        // ADR 0029 §10 — `pages × rate` is the PerPage price; an Sdk driver
        // (vision-llm) is metered per token by the laravel/ai lifecycle hook
        // after the fact, so the estimate must not invent a page price for
        // it: cost stays 0 and `metering` says why.
        $sdkMetered = $driverStatus['metering'] === OcrMeteringMode::Sdk->value;

        $items = [];
        $totalPages = 0;
        $totalCost = 0.0;
        $blockedPdf = false;
        $blockedImage = false;
        foreach ($batch->items as $item) {
            $row = $this->forItem($item, $stagingDisk, $enabled, $sdkMetered);
            // Per item (R27 additive): whether the configured driver can run
            // THIS kind of input here. The batch flag below is false as soon
            // as one staged item is blocked, and `driver_error` names why.
            $kind = SourceType::tryFrom((string) $item->source_type);
            $row['driver_available'] = match ($kind) {
                SourceType::PDF => $driverStatus['available_pdf'],
                SourceType::IMAGE => $driverStatus['available_image'],
                default => true,
            };
            // Only an item that NEEDS the driver blocks the batch: one that
            // would OCR, or one OCR itself refused (over a cap, uncountable,
            // multi-frame) — a text PDF, a text file or an unreadable object
            // is ingestible (or fails) without any driver.
            $needsDriver = $row['would_ocr'] || in_array($row['reason'], ['too_many_pages', 'too_many_bytes', 'pages_uncountable', 'multi_frame_image'], true);
            if (! $row['driver_available'] && $needsDriver) {
                $blockedPdf = $blockedPdf || $kind === SourceType::PDF;
                $blockedImage = $blockedImage || $kind === SourceType::IMAGE;
            }
            $items[] = $row;
            if ($row['would_ocr']) {
                $totalPages += $row['pages'];
                $totalCost += $row['cost'];
            }
        }
        // No staged item to judge by: the conservative answer (every kind).
        $anyItem = $items !== [];
        $available = $anyItem
            ? ! $blockedPdf && ! $blockedImage
            : $driverStatus['available_pdf'] && $driverStatus['available_image'];
        $error = $blockedPdf ? $driverStatus['error_pdf'] : ($blockedImage ? $driverStatus['error_image'] : null);
        if (! $anyItem && ! $available) {
            $error = $driverStatus['error_pdf'] ?? $driverStatus['error_image'];
        }

        $base = $this->meter->estimate(0);

        return [
            'enabled' => $enabled,
            'driver' => (string) config('kb.ocr.driver', 'tesseract'),
            // R14 — the modal must never promise a run the registry will
            // refuse (remote driver with the knob off, binary missing): false
            // as soon as one staged item needs a prerequisite the driver
            // lacks here; each item carries its own `driver_available`.
            'driver_available' => $available,
            'driver_error' => $error,
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
    /**
     * @return array{available_pdf: bool, available_image: bool, error_pdf: ?string, error_image: ?string, metering: string}
     */
    private function driverStatus(bool $enabled): array
    {
        if (! $enabled) {
            return ['available_pdf' => false, 'available_image' => false, 'error_pdf' => null, 'error_image' => null, 'metering' => OcrMeteringMode::PerPage->value];
        }
        try {
            $driver = $this->registry->configured();
            $metering = $driver->meteringMode()->value;
            $format = static fn (?string $reason): ?string => $reason === null ? null : sprintf('OCR driver "%s" is not available on this host: %s', $driver->name(), $reason);
            $pdf = $format($driver->unavailableReason(true));
            $image = $format($driver->unavailableReason(false));

            return ['available_pdf' => $pdf === null, 'available_image' => $image === null, 'error_pdf' => $pdf, 'error_image' => $image, 'metering' => $metering];
        } catch (Throwable $e) {
            return ['available_pdf' => false, 'available_image' => false, 'error_pdf' => $e->getMessage(), 'error_image' => $e->getMessage(), 'metering' => OcrMeteringMode::PerPage->value];
        }
    }

    /**
     * @return array{id: string, would_ocr: bool, pages: int, cost: float, reason: string, pages_exact: bool}
     */
    private function forItem(KbIngestBatchItem $item, string $stagingDisk, bool $enabled, bool $sdkMetered = false): array
    {
        $id = (string) $item->id;
        $type = SourceType::tryFrom((string) $item->source_type) ?? SourceType::UNKNOWN;
        if (! $enabled) {
            // `ocr_disabled` names only the items OCR WOULD have looked at
            // (an image, a scanned PDF); text and Markdown never needed it,
            // and a PDF with a text layer is ingested as text with the flag
            // off exactly as with it on — the probe says which (R14/R43: the
            // OFF answer is honest, never "every PDF would need OCR").
            $reason = match (true) {
                $type === SourceType::IMAGE => 'ocr_disabled',
                $type === SourceType::PDF => $this->offPathPdfReason($item, $stagingDisk),
                default => 'not_ocr_able',
            };

            return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => $reason, 'pages_exact' => true];
        }

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
            $imageBytes = $this->readStaged($stagingDisk, $stagingPath);
            if ($imageBytes === null) {
                // R14 — a failed read is not an empty image to price.
                return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'staged_file_unreadable', 'pages_exact' => true];
            }
            // The cap is enforced on the bytes the run will read, not on
            // the size recorded at staging: an object replaced or grown since
            // is refused here exactly as the service will refuse it (R14).
            if (strlen($imageBytes) > $maxBytes) {
                return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'too_many_bytes', 'pages_exact' => true];
            }
            // The SAME magic-byte check `OcrService::assertWithinLimits()`
            // applies before a driver runs: a staged object replaced or
            // corrupted since upload (a PDF, arbitrary bytes under the image
            // MIME) is refused here with the reason commit would give, not
            // priced as a run that cannot start (R14).
            $imageMime = FileTypeSniffer::imageMimeOf(substr($imageBytes, 0, 16));
            if ($imageMime === null) {
                return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'unrecognised_bytes', 'pages_exact' => true];
            }
            $pages = $this->ocr->pageCountForBytes($imageMime, $imageBytes);
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

        $bytes = $this->readStaged($stagingDisk, $stagingPath);
        if ($bytes === null) {
            // R14 — a failed read is not an empty scan to price as one page.
            return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'staged_file_unreadable', 'pages_exact' => true];
        }
        if (strlen($bytes) > $maxBytes) {
            return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'too_many_bytes', 'pages_exact' => true];
        }
        // The SAME signature check `OcrService::assertWithinLimits()` applies
        // before a driver runs: a staged object that is no PDF any more is
        // refused with the reason commit would give, never probed as a scan.
        if (! str_starts_with($bytes, '%PDF-')) {
            return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'unrecognised_bytes', 'pages_exact' => true];
        }
        // One parse: the probe reports the total page count too (a floor,
        // `pages_exact = false`, when the parser cannot read the file).
        $probe = $this->probe->probe($bytes);
        if ($probe['verdict'] === PdfTextLayerProbe::PRESENT) {
            return ['id' => $id, 'would_ocr' => false, 'pages' => 0, 'cost' => 0.0, 'reason' => 'text_layer_present', 'pages_exact' => true];
        }
        // The SAME decision `PdfConverter::ocrRoute()` takes for a PDF the
        // parser could not read: the `pdftotext` fallback runs first, and a
        // file it finds text in is ingested as text, never OCR'd — so the
        // modal must not quote pages and spend for it.
        if ($probe['verdict'] === PdfTextLayerProbe::UNREADABLE && $this->pdfTextFallback->textPages($bytes) !== null) {
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
     * The staged bytes, or null when the object cannot be read — a Flysystem
     * adapter may THROW on an unreadable or concurrently deleted object as
     * readily as return a non-string; both are the item's own
     * `staged_file_unreadable` state, never a 500 for the whole estimate.
     */
    private function readStaged(string $stagingDisk, string $stagingPath): ?string
    {
        try {
            $bytes = Storage::disk($stagingDisk)->get($stagingPath);
        } catch (\Throwable) {
            return null;
        }

        return is_string($bytes) ? $bytes : null;
    }

    /**
     * @return array{id: string, would_ocr: bool, pages: int, cost: float, reason: string, pages_exact: bool}
     */
    /**
     * OFF path, PDF: `text_layer_present` when the probe finds a text layer
     * (the converter extracts it, flag or no flag), `ocr_disabled` for a
     * scan — or for a file that cannot be read or is no PDF, which is the
     * conservative "this one OCR would have looked at".
     */
    private function offPathPdfReason(KbIngestBatchItem $item, string $stagingDisk): string
    {
        $stagingPath = (string) $item->staging_path;
        if ($stagingPath === '' || ! Storage::disk($stagingDisk)->exists($stagingPath)) {
            return 'ocr_disabled';
        }
        $bytes = $this->readStaged($stagingDisk, $stagingPath);
        if ($bytes === null || ! str_starts_with($bytes, '%PDF-')) {
            return 'ocr_disabled';
        }

        return $this->probe->probe($bytes)['verdict'] === PdfTextLayerProbe::PRESENT ? 'text_layer_present' : 'ocr_disabled';
    }

    private function priced(string $id, int $pages, string $reason, bool $exact = true, bool $sdkMetered = false): array
    {
        $estimate = $this->meter->estimate($pages);

        return ['id' => $id, 'would_ocr' => true, 'pages' => $estimate['pages'], 'cost' => $sdkMetered ? 0.0 : $estimate['cost'], 'reason' => $reason, 'pages_exact' => $exact];
    }

}
