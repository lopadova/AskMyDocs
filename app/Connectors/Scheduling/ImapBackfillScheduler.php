<?php

declare(strict_types=1);

namespace App\Connectors\Scheduling;

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
