<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EmailTriage\TriageHeartbeatJob;
use App\Jobs\EmailTriage\PumpTriageCampaignJob;
use App\Models\EmailTriageCampaign;
use App\Models\EmailTriageEvaluation;
use App\Services\EmailTriage\EmailTriageService;
use App\Services\EmailTriage\TriageProfiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class PumpEmailTriageCommand extends Command
{
    protected $signature = 'email-triage:pump';
    protected $description = 'Recover the durable email triage outbox and advance retrospective campaigns.';

    public function handle(EmailTriageService $triage, TriageProfiles $profiles): int
    {
        if (! Schema::hasTable('email_triage_evaluations') || ! $profiles->asynchronousQueues()) {
            return self::SUCCESS;
        }
        TriageHeartbeatJob::dispatch();
        EmailTriageEvaluation::query()->whereIn('status', ['waiting', 'running', 'completed'])
            ->whereNull('indexed_at')->where('index_status', '!=', 'failed')
            ->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subMinutes(10)))
            ->orderBy('id')->chunkById(100, function ($evaluations) use ($triage): void {
                foreach ($evaluations as $evaluation) {
                    $triage->enqueue($evaluation);
                }
            });
        // Cross-tenant enumeration is scheduler-only; every operation scopes to the row's tenant.
        EmailTriageCampaign::query()->where('status', 'running')->orderBy('id')->chunkById(20, function ($items): void {
            foreach ($items as $campaign) {
                PumpTriageCampaignJob::dispatch($campaign->id, $campaign->tenant_id);
            }
        });

        return self::SUCCESS;
    }
}
