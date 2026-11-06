<?php

declare(strict_types=1);

namespace App\FinOps;

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
 * v8.43/W3 (ADR 0035 §D4) — cross-encoder spend on the FinOps ledger.
 *
 * A hosted rerank API is a non-SDK call (same situation as OCR, ADR 0029 §8),
 * so the SDK lifecycle hook cannot meter it: this records one ledger row per
 * UNCACHED rerank call under `purpose_tag = cross_rerank`, priced as
 * estimated tokens × `kb.cross_rerank.price_per_million_tokens`. Cache hits
 * make no call and record nothing. A self-hosted endpoint has no per-token
 * price (default 0.0) but still leaves a row, so volume and latency are
 * visible next to every other AI line item.
 *
 * Never breaks a search: every failure is logged and swallowed (mirrors
 * {@see OcrCallMeter}); honours `ai-finops.enabled` / `ai-finops.metering`
 * through the recorder itself.
 */
class CrossRerankCallMeter
{
    public const PURPOSE_TAG = 'cross_rerank';

    public const PROVIDER = 'cross_rerank';

    public function __construct(private readonly TenantContext $tenants) {}

    public function meter(string $driverName, string $model, int $passages, int $estimatedTokens, int $latencyMs): void
    {
        try {
            $rate = max(0.0, (float) config('kb.cross_rerank.price_per_million_tokens', 0.0));
            $total = round($estimatedTokens / 1_000_000 * $rate, 8);

            $envelope = new AiCallEnvelope(
                traceId: (string) Str::uuid(),
                provider: self::PROVIDER,
                model: $model !== '' ? $model : $driverName,
                modality: Modality::Text,
                status: CallStatus::Recorded,
                tokens: new TokenUsage(input: max(0, $estimatedTokens)),
                cost: new CostBreakdown(total: $total, input: $total, currency: (string) config('ai-finops.currency.base', 'USD')),
                tenantId: $this->tenants->current(),
                purposeTag: self::PURPOSE_TAG,
                metadata: [
                    'driver' => $driverName,
                    'passages' => $passages,
                    'latency_ms' => $latencyMs,
                ],
                costMethod: CostMethod::Computed,
            );

            app(UsageRecorder::class)->record($envelope);
        } catch (Throwable $e) {
            Log::warning('kb.cross_rerank.finops.meter_failed', ['driver' => $driverName, 'error_class' => $e::class]);
        }
    }
}
