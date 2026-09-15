<?php

declare(strict_types=1);

namespace App\Realtime;

use AgentsFullDuplex\RealtimeAgent\Events\AgentUsageRecorded;
use App\Models\RealtimeAgentSessionLink;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Padosoft\LaravelAiFinOps\Contracts\UsageRecorder;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;
use Padosoft\LaravelAiFinOps\Data\CostBreakdown;
use Padosoft\LaravelAiFinOps\Data\TokenUsage;
use Padosoft\LaravelAiFinOps\Enums\CallStatus;
use Padosoft\LaravelAiFinOps\Enums\CostMethod;
use Padosoft\LaravelAiFinOps\Enums\Modality;
use Padosoft\LaravelAiFinOps\Models\UsageRecord as FinOpsUsageRecord;

/** Projects the bridge's provider audit into the host-wide FinOps ledger. */
final readonly class ProjectRealtimeUsageToFinOps
{
    public function __construct(private UsageRecorder $recorder) {}

    public function handle(AgentUsageRecorded $event): void
    {
        $usage = $event->usage;
        $traceId = 'realtime-agent:'.$usage->id;

        try {
            if (FinOpsUsageRecord::query()->where('trace_id', $traceId)->exists()) {
                return;
            }

            $link = RealtimeAgentSessionLink::query()
                ->whereKey($usage->sessionId)
                ->first();

            if (! $link instanceof RealtimeAgentSessionLink) {
                Log::warning('Realtime usage could not be attributed to a tenant.', [
                    'session_id' => $usage->sessionId,
                    'usage_id' => $usage->id,
                ]);

                return;
            }

            $input = $this->units($usage->units, [
                'input_text_tokens',
                'input_audio_tokens',
                'input_image_tokens',
            ]);
            $output = $this->units($usage->units, [
                'output_text_tokens',
                'output_audio_tokens',
            ]);
            $cached = $this->units($usage->units, [
                'cached_input_text_tokens',
                'cached_input_audio_tokens',
                'cached_input_image_tokens',
            ]);
            $amount = is_numeric($usage->amount) ? (float) $usage->amount : 0.0;
            $isProviderInvoice = $usage->kind === 'provider_invoice' && $usage->status === 'final';

            $this->recorder->record(new AiCallEnvelope(
                traceId: $traceId,
                provider: $usage->provider,
                model: $usage->model ?? 'realtime-session',
                modality: Modality::Audio,
                status: CallStatus::Recorded,
                tokens: new TokenUsage(input: $input, output: $output, cached: $cached),
                cost: new CostBreakdown(total: $amount, currency: $usage->currency),
                tenantId: $link->tenant_id,
                userId: $link->user_id,
                agentStep: 'realtime_transport',
                purposeTag: 'askmydocs_live_voice',
                occurredAt: new DateTimeImmutable($usage->occurredAt),
                metadata: [
                    'realtime_agent_session_id' => $usage->sessionId,
                    'realtime_agent_usage_id' => $usage->id,
                    'kind' => $usage->kind,
                    'status' => $usage->status,
                    'units' => $usage->units,
                    'pricing' => $usage->pricing,
                    'unpriced' => $usage->amount === null,
                ],
                costMethod: $isProviderInvoice ? CostMethod::Actual : CostMethod::Computed,
                billedCost: $isProviderInvoice ? $amount : null,
                billedCurrency: $isProviderInvoice ? $usage->currency : null,
            ));
        } catch (\Throwable $exception) {
            // Transport audit must never make an otherwise-valid live turn fail.
            Log::warning('Realtime usage FinOps projection failed.', [
                'session_id' => $usage->sessionId,
                'usage_id' => $usage->id,
                'exception' => $exception::class,
            ]);
        }
    }

    /** @param array<string,int|float> $units @param list<string> $keys */
    private function units(array $units, array $keys): int
    {
        return (int) array_sum(array_map(
            static fn (string $key): int => max(0, (int) ($units[$key] ?? 0)),
            $keys,
        ));
    }
}
