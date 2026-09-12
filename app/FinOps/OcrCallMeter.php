<?php

declare(strict_types=1);

namespace App\FinOps;

use App\Services\Kb\Ocr\OcrDriver;
use App\Services\Kb\Ocr\OcrMeteringMode;
use App\Services\Kb\Ocr\OcrResult;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\CallStatus;
use Padosoft\LaravelAiFinOps\Enums\CostMethod;
use Padosoft\LaravelAiFinOps\Enums\Modality;
use Throwable;

/**
 * v8.36 / ADR 0029 §8 — OCR spend on the FinOps ledger.
 *
 * Locally-priced drivers (docling, tesseract, mistral-ocr) have no token
 * usage the SDK lifecycle hook could meter, so this records one ledger row
 * per OCR run under `purpose_tag = ocr`, `modality = image`, priced as
 * pages × `kb.ocr.rate_per_page` in the FinOps base currency. `vision-llm`
 * (`OcrMeteringMode::Sdk`) is metered per token by the SDK hook and is
 * deliberately skipped here — the same call must never appear twice.
 *
 * Never breaks an ingest: every failure is logged and swallowed (mirrors
 * {@see AiCallMeter}). Honours `ai-finops.enabled` / `ai-finops.metering`
 * through the recorder itself.
 */
class OcrCallMeter
{
    public const PURPOSE_TAG = 'ocr';

    public const PROVIDER = 'ocr';

    public function __construct(private readonly TenantContext $tenants) {}

    public function meter(OcrResult $result, OcrDriver $driver, string $sourcePath): void
    {
        if ($driver->meteringMode() === OcrMeteringMode::Sdk) {
            return;
        }

        try {
            $pages = $result->pageCount();
            $rate = max(0.0, (float) config('kb.ocr.rate_per_page', 0.0));
            $currency = (string) config('ai-finops.currency.base', 'USD');
            $total = round($pages * $rate, 8);

            $envelope = new AiCallEnvelope(
                traceId: (string) Str::uuid(),
                provider: self::PROVIDER,
                model: $driver->name(),
                modality: Modality::Image,
                status: CallStatus::Recorded,
                tokens: new TokenUsage(),
                cost: new CostBreakdown(total: $total, input: $total, currency: $currency),
                tenantId: $this->tenants->current(),
                purposeTag: self::PURPOSE_TAG,
                metadata: [
                    'pages' => $pages,
                    'rate_per_page' => $rate,
                    'driver' => $driver->name(),
                    'source_path' => $sourcePath,
                    'mean_confidence' => $result->meanConfidence(),
                ],
                costMethod: CostMethod::Computed,
            );

            app(UsageRecorder::class)->record($envelope);
        } catch (Throwable $e) {
            Log::warning('kb.ocr.finops.meter_failed', [
                'driver' => $driver->name(),
                'source_path' => $sourcePath,
                'error_class' => $e::class,
            ]);
        }
    }

    /**
     * Cost of `$pages` pages at the configured rate — the number the upload
     * modal shows before commit.
     *
     * @return array{pages: int, rate_per_page: float, cost: float, currency: string}
     */
    public function estimate(int $pages): array
    {
        $rate = max(0.0, (float) config('kb.ocr.rate_per_page', 0.0));

        return [
            'pages' => max(0, $pages),
            'rate_per_page' => $rate,
            'cost' => round(max(0, $pages) * $rate, 6),
            'currency' => (string) config('ai-finops.currency.base', 'USD'),
        ];
    }
}
