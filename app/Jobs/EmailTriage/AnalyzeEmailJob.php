<?php

declare(strict_types=1);

namespace App\Jobs\EmailTriage;

use App\Models\EmailTriageEvaluation;
use App\Models\EmailTriageMessage;
use App\Services\EmailTriage\EmailTriageService;
use App\Services\EmailTriage\TriageFailure;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class AnalyzeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public int $timeout = 150;
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $evaluationId, public readonly string $tenantId)
    {
        $this->onQueue(config('email-triage.queue', 'email-triage'));
        $this->onConnection(config('email-triage.connection', 'email-triage'));
    }

    public function handle(EmailTriageService $triage, TenantContext $context): void
    {
        Cache::put('email-triage:worker-heartbeat', now()->toIso8601String(), 180);
        $previous = $context->current();
        $context->set($this->tenantId);
        try {
            $evaluation = EmailTriageEvaluation::query()->forTenant($this->tenantId)->find($this->evaluationId);
            if (! $evaluation || $evaluation->status === 'failed') {
                return;
            }
            if ($evaluation->retry_at && $evaluation->retry_at->isFuture()) {
                $this->release(max(1, (int) ceil(now()->diffInSeconds($evaluation->retry_at, false))));

                return;
            }
            $triage->process($this->evaluationId, $this->tenantId);
        } finally {
            $context->set($previous);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $evaluation = EmailTriageEvaluation::query()->forTenant($this->tenantId)->find($this->evaluationId);
        if ($evaluation?->indexed_at) {
            return;
        }
        if ($evaluation && $evaluation->result !== null) {
            $evaluation->forceFill(['index_status' => 'failed', 'error_code' => TriageFailure::code($exception)])->save();
        } elseif ($evaluation && $evaluation->attempts >= 4) {
            $evaluation->forceFill(['status' => 'failed', 'error_code' => $evaluation->error_code ?: TriageFailure::code($exception)])->save();
            EmailTriageMessage::query()->forTenant($this->tenantId)->whereKey($evaluation->message_id)
                ->where('evaluation_id', $evaluation->id)->update(['status' => 'failed']);
        } elseif ($evaluation) {
            $evaluation->forceFill(['status' => 'waiting', 'queued_at' => null, 'retry_at' => now()->addSeconds(30),
                'error_code' => class_basename($exception)])->save();
        }
    }
}
