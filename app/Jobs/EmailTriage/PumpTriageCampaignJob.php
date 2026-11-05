<?php

declare(strict_types=1);

namespace App\Jobs\EmailTriage;

use App\Models\EmailTriageCampaign;
use App\Services\EmailTriage\TriageCampaigns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class PumpTriageCampaignJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 150;
    public int $tries = 3;

    public function __construct(public readonly int $campaignId, public readonly string $tenantId)
    {
        $this->onQueue(config('email-triage.queue', 'email-triage'));
        $this->onConnection(config('email-triage.connection', 'email-triage'));
    }

    public function handle(TriageCampaigns $campaigns): void
    {
        $campaign = EmailTriageCampaign::query()->forTenant($this->tenantId)->find($this->campaignId);
        if ($campaign) {
            $campaigns->pump($campaign);
        }
    }
}
