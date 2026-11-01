<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agent\AgentEventPublisher;
use App\Agent\AgentTerminalExecutionFinalizer;
use App\Models\AgentRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Operator repair of one historical terminal run; never replays its requests. */
final class ReconcileTerminalAgentRunCommand extends Command
{
    protected $signature = 'agent:reconcile-terminal-run
                            {run_id : UUID of the already terminal run}
                            {--tenant= : Required tenant ID}';

    protected $description = 'Close unobserved tools and waves belonging to one terminal agent run';

    public function handle(AgentTerminalExecutionFinalizer $finalizer, AgentEventPublisher $events): int
    {
        $tenant = trim((string) $this->option('tenant'));
        if ($tenant === '') {
            $this->error('An explicit --tenant is required.');

            return self::INVALID;
        }

        return DB::transaction(function () use ($tenant, $finalizer, $events): int {
            $run = AgentRun::query()->forTenant($tenant)->where('run_id', $this->argument('run_id'))
                ->lockForUpdate()->first();
            if ($run === null || ! $run->isTerminal()) {
                $this->error('No terminal run found in the selected tenant.');

                return self::FAILURE;
            }

            $outcomes = $finalizer->close($run);
            foreach ($outcomes as $outcome) {
                $events->publish($run, 'tool.failed', 'tool.failed', ['tool' => $outcome['tool']],
                    [...$outcome, 'outcome_unknown' => true], canCancel: false);
            }
            $this->info('Reconciled terminal run; closed tools: '.count($outcomes).'.');

            return self::SUCCESS;
        });
    }
}
