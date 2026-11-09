<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class StaleAgentRunRecovery
{
    /** Explicit tenant + IDs; never expire confirmations or external tasks by age. */
    public function recover(string $tenant, int $id, CarbonInterface $before, bool $apply): string
    {
        return DB::transaction(function () use ($tenant, $id, $before, $apply): string {
            $run = AgentRun::query()->forTenant($tenant)->whereKey($id)->lockForUpdate()->first();
            if ($run === null || !in_array($run->status, [AgentRun::STATUS_QUEUED, AgentRun::STATUS_RUNNING], true)) {
                return 'skipped';
            }
            $eventAt = $run->events()->max('created_at');
            if ($run->updated_at->gte($before) || ($eventAt !== null && $before->lte($eventAt))) {
                return 'recent_activity';
            }
            if (! $apply) {
                return 'candidate';
            }
            if (app(VerifiedAnswerCheckpoint::class)->recover($run, 'stale_run_reconciled')) {
                return 'recovered_answer';
            }
            app(AgentRunControl::class)->cancel($run);

            return 'cancelled';
        });
    }
}
