<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AgentsFullDuplex\RealtimeAgent\Models\AgentSessionRecord;
use App\Models\RealtimeAgentSessionLink;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Removes expired bridge sessions and their cascaded transcript/audit rows. */
final class PruneRealtimeAgentSessionsCommand extends Command
{
    protected $signature = 'realtime-agent:prune
                            {--days= : Override REALTIME_AGENT_RETENTION_DAYS}
                            {--tenant= : Restrict to one tenant_id}
                            {--dry-run : Count sessions without deleting}';

    protected $description = 'Delete expired realtime-agent sessions older than the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('realtime-agent.retention_days', 90));
        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping realtime-agent rotation.');

            return self::SUCCESS;
        }

        $cutoff = CarbonImmutable::now()->subDays($days);
        $query = RealtimeAgentSessionLink::query()->where('expires_at', '<', $cutoff);
        $tenantId = trim((string) ($this->option('tenant') ?? ''));
        if ($tenantId !== '') {
            $query->forTenant($tenantId);
        }

        $sessionIds = $query->pluck('session_id');
        $count = $sessionIds->count();
        if ((bool) $this->option('dry-run')) {
            $this->info("Would delete {$count} realtime-agent session(s) expired before {$cutoff->toIso8601String()}.");

            return self::SUCCESS;
        }

        $sessionIds->chunk(500)->each(static function ($ids): void {
            AgentSessionRecord::query()->whereIn('id', $ids)->delete();
        });
        $this->info("Deleted {$count} realtime-agent session(s) expired before {$cutoff->toIso8601String()}.");

        return self::SUCCESS;
    }
}
