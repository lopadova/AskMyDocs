<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use App\Models\ImapBackfillWindow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Throwable;

/** One trace per independently resumable date-window batch; no mail contents. */
final class ImapBackfillBatchTrace
{
    private readonly array $context;

    private readonly float $startedAt;

    public function __construct(ConnectorInstallation $installation, ImapBackfillWindow $window, int $limit)
    {
        $this->startedAt = microtime(true);
        $this->context = [
            'diagnostic_id' => (string) Str::uuid(),
            'installation_id' => $installation->id,
            'tenant_id' => $installation->tenant_id,
            'window_id' => $window->id,
            'mailbox_hash' => ImapBackfillDiagnostics::mailboxHash($window->mailbox),
            'window_start' => $window->window_start->toDateString(),
            'window_end' => $window->window_end->toDateString(),
            'snapshot_uid_validity' => $window->snapshot_uid_validity,
            'snapshot_max_uid' => $window->snapshot_max_uid,
            'after_uid' => $window->last_uid,
            'batch_limit' => $limit,
        ];
        $this->event('batch started', ImapBackfillDiagnostics::runtime());
    }

    public function measure(string $phase, callable $operation, array $context = []): mixed
    {
        $startedAt = microtime(true);
        try {
            $result = $operation();
            $this->event('phase completed', $context + [
                'phase' => $phase,
                'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($startedAt),
                'result_count' => is_array($result) ? count($result) : null,
            ]);

            return $result;
        } catch (Throwable $exception) {
            Log::error('[imap-download] phase failed', $this->context + $context + [
                'phase' => $phase,
                'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($startedAt),
                'exception_chain' => ImapBackfillDiagnostics::exceptionChain($exception),
            ] + ImapBackfillDiagnostics::runtime());

            throw $exception;
        }
    }

    public function event(string $event, array $context = []): void
    {
        Log::info('[imap-download] '.$event, $this->context + $context);
    }

    public function completed(ImapBackfillBatchResult $result): void
    {
        $this->event('batch downloaded', [
            'last_uid' => $result->lastUid,
            'processed_messages' => $result->processedMessages,
            'persisted_documents' => $result->dispatchedDocuments,
            'has_more' => $result->hasMore,
            'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($this->startedAt),
        ] + ImapBackfillDiagnostics::runtime());
    }

    public function failed(Throwable $exception, array $context = []): void
    {
        Log::error('[imap-download] batch failed', $this->context + $context + [
            'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($this->startedAt),
            'exception_chain' => ImapBackfillDiagnostics::exceptionChain($exception),
        ] + ImapBackfillDiagnostics::runtime());
    }
}
