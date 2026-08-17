<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Connectors\Imap\Backfill\ImapBackfillDiagnostics;
use App\Jobs\Imap\DiscoverImapBackfillJob;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Temporary trace around the discovery job, including queue-lock short circuits. */
final class TraceImapBackfillDiscovery
{
    public function __construct(
        private readonly ?string $mailboxLockKey,
        private readonly int $overlapReleaseAfterSeconds,
        private readonly int $overlapTtlSeconds,
    ) {}

    public function handle(DiscoverImapBackfillJob $job, Closure $next): mixed
    {
        $startedAt = microtime(true);
        $context = [
            'backfill_id' => $job->backfillId,
            'tenant_id' => $job->tenantId,
            'queue_job_id' => $job->job?->getJobId(),
            'queue_attempt' => $job->attempts(),
            'queue_name' => $job->queue,
            'queue_connection' => $job->connection,
            'retry_deadline' => $this->retryDeadline($job),
            'retry_window_minutes' => (int) config('connectors.imap.mailbox_lock.requeue_window_minutes', 30),
            'mailbox_lock_key' => $this->mailboxLockKey,
            'overlap_release_after_seconds' => $this->overlapReleaseAfterSeconds,
            'overlap_ttl_seconds' => $this->overlapTtlSeconds,
        ] + ImapBackfillDiagnostics::runtime();

        Log::info('[imap-backfill-diag] discovery queue attempt started', $context);

        try {
            $result = $next($job);
        } catch (Throwable $exception) {
            Log::error('[imap-backfill-diag] discovery queue attempt threw', $context + [
                'handle_entered' => $job->diagnosticHandleEntered,
                'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($startedAt),
                'exception_chain' => ImapBackfillDiagnostics::exceptionChain($exception),
            ]);

            throw $exception;
        }

        $released = $job->job?->isReleased() ?? false;
        if ($released && ! $job->diagnosticHandleEntered) {
            Log::warning('[imap-backfill-diag] discovery skipped because queue overlap lock is busy', $context + [
                'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($startedAt),
            ]);
        } else {
            Log::info('[imap-backfill-diag] discovery queue attempt finished', $context + [
                'handle_entered' => $job->diagnosticHandleEntered,
                'released' => $released,
                'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($startedAt),
            ]);
        }

        return $result;
    }

    private function retryDeadline(DiscoverImapBackfillJob $job): ?string
    {
        if ($job->job === null || ! method_exists($job->job, 'payload')) {
            return null;
        }

        $timestamp = $job->job->payload()['retryUntil'] ?? null;

        return is_numeric($timestamp) ? date(DATE_ATOM, (int) $timestamp) : null;
    }
}
