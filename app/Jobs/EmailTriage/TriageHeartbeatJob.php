<?php

declare(strict_types=1);

namespace App\Jobs\EmailTriage;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

final class TriageHeartbeatJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct()
    {
        $this->onQueue(config('email-triage.queue', 'email-triage'));
        $this->onConnection(config('email-triage.connection', 'email-triage'));
    }

    public function handle(): void
    {
        Cache::put('email-triage:worker-heartbeat', now()->toIso8601String(), 180);
    }
}
