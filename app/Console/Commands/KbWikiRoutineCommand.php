<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Routines\WikiRoutineService;
use Illuminate\Console\Command;

/**
 * v8.39/W5 (ADR 0033 §8) — PHP/CLI surface for the Auto-Wiki maintenance
 * routine. `status` is always available (answers a clean "disabled" state
 * when `KB_WIKI_ROUTINE_ENABLED` is off, R43); `run` requires the flag on.
 * Both delegate to {@see WikiRoutineService} — the same core the HTTP
 * controller and `KbWikiRoutineStatusTool` use (R44).
 */
final class KbWikiRoutineCommand extends Command
{
    protected $signature = 'kb:wiki-routine
        {action : status|run}
        {--tenant=default : tenant to scope to}';

    protected $description = 'Read the Auto-Wiki maintenance routine\'s status, or trigger a run now (ADR 0033).';

    public function handle(WikiRoutineService $routines): int
    {
        $action = (string) $this->argument('action');
        $tenant = (string) $this->option('tenant');

        if (! in_array($action, ['status', 'run'], true)) {
            $this->error("Unknown action \"{$action}\" — expected \"status\" or \"run\".");

            return self::FAILURE;
        }

        if (! $routines->enabled()) {
            $this->line('{"disabled": true, "flag": "KB_WIKI_ROUTINE_ENABLED"}');

            return $action === 'run' ? self::FAILURE : self::SUCCESS;
        }

        if ($action === 'status') {
            $status = $routines->status($tenant);
            if (! $status['provisioned']) {
                $this->info("No wiki-maintenance routine provisioned yet for tenant \"{$tenant}\". Run `kb:wiki-routine run --tenant={$tenant}` to create one.");

                return self::SUCCESS;
            }

            $this->info("Routine status: {$status['status']} (next run: ".($status['next_run_at'] ?? 'n/a').')');
            $lastRun = $status['last_run'];
            if ($lastRun !== null) {
                $this->line("Last run [{$lastRun['outcome']}]: {$lastRun['message']}");
            }

            return self::SUCCESS;
        }

        $result = $routines->run($tenant);
        $this->info("Run [{$result['outcome']}]: {$result['message']}");

        return self::SUCCESS;
    }
}
