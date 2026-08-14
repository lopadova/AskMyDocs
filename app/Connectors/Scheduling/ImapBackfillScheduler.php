<?php

declare(strict_types=1);

namespace App\Connectors\Scheduling;

use App\Jobs\Imap\DiscoverImapBackfillJob;
use App\Jobs\Imap\PumpImapBackfillJob;
use App\Models\ImapBackfill;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

final class ImapBackfillScheduler
{
    public function registerSchedules(Schedule $schedule): void
    {
        $schedule->call(fn (): int => $this->pumpActive())
            ->everyMinute()
            ->name('connectors.imap.pump-backfills')
            ->onOneServer()
            ->withoutOverlapping();
    }

    public function pumpActive(): int
    {
        if (! Schema::hasTable('imap_backfills') || ! config('connectors.imap.backfill.enabled', true)) {
            return 0;
        }

        $count = 0;
        $staleBefore = now()->subMinutes(max(
            1,
            (int) config('connectors.imap.backfill.stale_after_minutes', 20),
        ));

        // The campaign row is committed before its discovery job is published.
        // Recover a lost publish (worker crash / queue outage) once its heartbeat
        // proves that no discovery worker made progress within the stale window.
        ImapBackfill::query()
            ->where('status', ImapBackfill::STATUS_DISCOVERING)
            ->where(fn ($query) => $query
                ->whereNull('heartbeat_at')
                ->orWhere('heartbeat_at', '<=', $staleBefore))
            ->orderBy('id')
            ->chunkById(100, function ($backfills) use (&$count): void {
                foreach ($backfills as $backfill) {
                    DiscoverImapBackfillJob::dispatch($backfill->id, (string) $backfill->tenant_id)
                        ->onQueue((string) config('connectors.imap.backfill.queue', 'connectors'));
                    $count++;
                }
            });

        ImapBackfill::query()
            ->where('status', ImapBackfill::STATUS_RUNNING)
            ->orderBy('id')
            ->chunkById(100, function ($backfills) use (&$count): void {
                foreach ($backfills as $backfill) {
                    PumpImapBackfillJob::dispatch($backfill->id, (string) $backfill->tenant_id)
                        ->onQueue((string) config('connectors.imap.backfill.queue', 'connectors'));
                    $count++;
                }
            });

        return $count;
    }
}
