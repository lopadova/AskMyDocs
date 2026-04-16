<?php

namespace App\Console\Commands;

use App\Models\ChatLog;
use Illuminate\Console\Command;

class PruneChatLogsCommand extends Command
{
    protected $signature = 'chat-log:prune {--days= : Override CHAT_LOG_RETENTION_DAYS}';

    protected $description = 'Rotate chat_logs by deleting rows older than N days.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('chat-log.retention_days', 90));

        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping rotation.');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = ChatLog::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} chat_logs rows older than {$days} days (cutoff: {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
