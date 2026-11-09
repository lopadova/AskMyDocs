<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agent\StaleAgentRunRecovery;
use Illuminate\Console\Command;

final class RecoverStaleAgentRunsCommand extends Command
{
    protected $signature = 'agent:recover-stale {tenant} {--ids= : Comma-separated internal run IDs} {--older-than=60 : Minimum inactivity in minutes} {--apply : Recover verified answers or cancel the selected abandoned runs}';
    protected $description = 'Inspect or reconcile explicitly selected stale queued/running agent runs without rerunning them.';

    public function handle(StaleAgentRunRecovery $recovery): int
    {
        $tenant = (string) $this->argument('tenant');
        $ids = (string) $this->option('ids');
        $minutes = filter_var($this->option('older-than'), FILTER_VALIDATE_INT);
        if (! preg_match('/^[a-z0-9_-]{1,50}$/', $tenant) || ! preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/', $ids) || $minutes === false || $minutes < 60) {
            $this->error('Provide a valid tenant, explicit --ids=1,2 and --older-than of at least 60 minutes.');

            return self::FAILURE;
        }
        $before = now()->subMinutes($minutes);
        foreach (array_unique(array_map('intval', explode(',', $ids))) as $id) {
            $this->line(json_encode(['tenant' => $tenant, 'id' => $id, 'result' => $recovery->recover($tenant, $id, $before, (bool) $this->option('apply'))]));
        }

        return self::SUCCESS;
    }
}
