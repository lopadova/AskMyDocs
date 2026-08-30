<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use RuntimeException;
use Throwable;

final class ImapBackfillDiscovery
{
    private const FIRST_MESSAGE_SEARCH_LIMIT = 500;

    public function __construct(private readonly ImapBackfillClientProviderContract $clients) {}

    public function discover(ConnectorInstallation $installation, ImapBackfill $backfill): void
    {
        $diagnosticId = (string) Str::uuid();
        $startedAt = microtime(true);
        $phaseStartedAt = $startedAt;
        $phase = 'create_client';
        $mailboxHash = null;
        $client = null;
        $primaryException = null;
        $config = (array) ($backfill->settings_json ?? []);

        Log::info('[imap-backfill-diag] discovery started', [
            'diagnostic_id' => $diagnosticId,
            'backfill_id' => $backfill->id,
            'tenant_id' => $backfill->tenant_id,
            'installation_id' => $installation->id,
        ] + ImapBackfillDiagnostics::runtime());

        try {
            $client = $this->clients->forInstallation($installation);
            Log::info('[imap-backfill-diag] discovery phase completed', [
                'diagnostic_id' => $diagnosticId,
                'phase' => $phase,
                'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($phaseStartedAt),
            ]);

            $phase = 'list_mailboxes';
            $phaseStartedAt = microtime(true);
            $mailboxes = $this->selectedMailboxes($client->mailboxes(), $config);
            Log::info('[imap-backfill-diag] discovery phase completed', [
                'diagnostic_id' => $diagnosticId,
                'phase' => $phase,
                'selected_mailboxes' => count($mailboxes),
                'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($phaseStartedAt),
            ]);
            $rows = [];
            $totalMessages = 0;
            $cutoffEnd = $backfill->cutoff_at->copy()->addDay()->startOfDay();
            $absoluteStart = Carbon::parse((string) config('connectors.imap.backfill.absolute_start', '1970-01-01'))->startOfDay();

            foreach ($mailboxes as $mailbox) {
                $mailboxHash = ImapBackfillDiagnostics::mailboxHash($mailbox);
                $phase = 'snapshot_mailbox';
                $phaseStartedAt = microtime(true);
                $snapshot = $client->snapshotMailbox($mailbox);
                Log::info('[imap-backfill-diag] discovery mailbox snapshot completed', [
                    'diagnostic_id' => $diagnosticId,
                    'phase' => $phase,
                    'mailbox_hash' => $mailboxHash,
                    'message_count' => $snapshot->messageCount,
                    'max_uid' => $snapshot->maxUid,
                    'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($phaseStartedAt),
                ]);
                if ($snapshot->messageCount === 0 || $snapshot->maxUid === 0) {
                    continue;
                }

                $phase = 'search_first_uid';
                $phaseStartedAt = microtime(true);
                $firstUids = $client->uidsBetween(
                    $mailbox,
                    $absoluteStart,
                    $cutoffEnd,
                    throughUid: $snapshot->maxUid,
                    limit: self::FIRST_MESSAGE_SEARCH_LIMIT,
                );
                Log::info('[imap-backfill-diag] discovery first UID search completed', [
                    'diagnostic_id' => $diagnosticId,
                    'phase' => $phase,
                    'mailbox_hash' => $mailboxHash,
                    'uids_found' => count($firstUids),
                    'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($phaseStartedAt),
                ]);
                if ($firstUids === []) {
                    continue;
                }

                $totalMessages += $snapshot->messageCount;
                $phase = 'fetch_first_internal_date';
                $phaseStartedAt = microtime(true);
                $firstDate = $client->internalDate($mailbox, $firstUids[0]);
                Log::info('[imap-backfill-diag] discovery first INTERNALDATE fetched', [
                    'diagnostic_id' => $diagnosticId,
                    'phase' => $phase,
                    'mailbox_hash' => $mailboxHash,
                    'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($phaseStartedAt),
                ]);
                $firstMonth = $firstDate->copy()->startOfMonth();
                $firstMonth = $firstMonth->max($absoluteStart)->min($cutoffEnd->copy()->subDay()->startOfMonth());

                // A single prefix window guarantees coverage even when messages
                // were moved and UID order differs from their internal date.
                if ($absoluteStart->lt($firstMonth)) {
                    $rows[] = $this->windowRow($installation, $backfill, $mailbox, $absoluteStart, $firstMonth, $snapshot->uidValidity, $snapshot->maxUid);
                }

                for ($start = $firstMonth->copy(); $start->lt($cutoffEnd); $start->addMonth()) {
                    $end = $start->copy()->addMonth()->min($cutoffEnd);
                    $rows[] = $this->windowRow($installation, $backfill, $mailbox, $start, $end, $snapshot->uidValidity, $snapshot->maxUid);
                }
            }

            $mailboxHash = null;
            $phase = 'persist_windows';
            $phaseStartedAt = microtime(true);
            DB::transaction(function () use ($backfill, $rows, $totalMessages): void {
                ImapBackfillWindow::query()
                    ->forTenant((string) $backfill->tenant_id)
                    ->where('imap_backfill_id', $backfill->id)
                    ->delete();

                foreach (array_chunk($rows, 500) as $chunk) {
                    ImapBackfillWindow::query()->insert($chunk);
                }

                $backfill->forceFill([
                    'status' => ImapBackfill::STATUS_RUNNING,
                    'total_messages' => $totalMessages,
                    'processed_messages' => 0,
                    'dispatched_documents' => 0,
                    'total_windows' => count($rows),
                    'completed_windows' => 0,
                    'started_at' => $backfill->started_at ?? now(),
                    'heartbeat_at' => now(),
                    'error_json' => null,
                ])->save();
            });
            Log::info('[imap-backfill-diag] discovery completed', [
                'diagnostic_id' => $diagnosticId,
                'phase' => $phase,
                'mailboxes' => count($mailboxes),
                'windows' => count($rows),
                'messages' => $totalMessages,
                'phase_elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($phaseStartedAt),
                'total_elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($startedAt),
            ] + ImapBackfillDiagnostics::runtime());
        } catch (Throwable $exception) {
            $primaryException = $exception;
            Log::error('[imap-backfill-diag] discovery failed in phase', [
                'diagnostic_id' => $diagnosticId,
                'backfill_id' => $backfill->id,
                'tenant_id' => $backfill->tenant_id,
                'installation_id' => $installation->id,
                'phase' => $phase,
                'mailbox_hash' => $mailboxHash,
                'phase_elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($phaseStartedAt),
                'total_elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($startedAt),
                'exception_chain' => ImapBackfillDiagnostics::exceptionChain($exception),
            ] + ImapBackfillDiagnostics::runtime());

            throw new RuntimeException(sprintf(
                'IMAP discovery failed [diag=%s phase=%s%s]: %s',
                $diagnosticId,
                $phase,
                $mailboxHash === null ? '' : ' mailbox='.$mailboxHash,
                $exception->getMessage(),
            ), previous: $exception);
        } finally {
            if ($client !== null) {
                $closeStartedAt = microtime(true);
                try {
                    $client->close();
                    Log::info('[imap-backfill-diag] discovery client closed', [
                        'diagnostic_id' => $diagnosticId,
                        'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($closeStartedAt),
                    ]);
                } catch (Throwable $closeException) {
                    Log::error('[imap-backfill-diag] discovery client close failed', [
                        'diagnostic_id' => $diagnosticId,
                        'primary_exception_present' => $primaryException !== null,
                        'elapsed_ms' => ImapBackfillDiagnostics::elapsedMs($closeStartedAt),
                        'exception_chain' => ImapBackfillDiagnostics::exceptionChain($closeException),
                    ]);

                    if ($primaryException === null) {
                        throw new RuntimeException(sprintf(
                            'IMAP discovery failed while closing client [diag=%s]: %s',
                            $diagnosticId,
                            $closeException->getMessage(),
                        ), previous: $closeException);
                    }
                }
            }
        }
    }

    /**
     * @param list<string> $live
     * @param array<string,mixed> $config
     * @return list<string>
     */
    private function selectedMailboxes(array $live, array $config): array
    {
        $include = array_map('strval', (array) ($config['folders']['include'] ?? []));
        $exclude = array_map('strval', (array) ($config['folders']['exclude'] ?? []));
        if ($include !== []) {
            return array_values(array_filter($live, static fn (string $mailbox): bool => in_array($mailbox, $include, true)));
        }

        return array_values(array_filter($live, static fn (string $mailbox): bool => ! in_array($mailbox, $exclude, true)));
    }

    /** @return array<string,mixed> */
    private function windowRow(
        ConnectorInstallation $installation,
        ImapBackfill $backfill,
        string $mailbox,
        Carbon $start,
        Carbon $end,
        int $uidValidity,
        int $maxUid,
    ): array {
        $now = now();

        return [
            'tenant_id' => $backfill->tenant_id,
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => $mailbox,
            'window_start' => $start->toDateString(),
            'window_end' => $end->toDateString(),
            'status' => ImapBackfillWindow::STATUS_PENDING,
            'snapshot_uid_validity' => $uidValidity,
            'snapshot_max_uid' => $maxUid,
            'last_uid' => 0,
            'expected_messages' => 0,
            'processed_messages' => 0,
            'dispatched_documents' => 0,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
