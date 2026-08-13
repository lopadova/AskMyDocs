<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

final class ImapBackfillDiscovery
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    public function discover(ConnectorInstallation $installation, ImapBackfill $backfill): void
    {
        $client = ImapBackfillMailboxClient::forInstallation($installation, $this->registry);
        $config = (array) ($backfill->settings_json ?? []);

        try {
            $mailboxes = $this->selectedMailboxes($client->mailboxes(), $config);
            $rows = [];
            $totalMessages = 0;
            $cutoffEnd = $backfill->cutoff_at->copy()->addDay()->startOfDay();
            $absoluteStart = Carbon::parse((string) config('connectors.imap.backfill.absolute_start', '1970-01-01'))->startOfDay();

            foreach ($mailboxes as $mailbox) {
                $state = $client->selectMailbox($mailbox);
                $uids = $client->allUids($mailbox);
                if ($uids === []) {
                    continue;
                }

                $totalMessages += count($uids);
                $first = $client->fetchMessage($mailbox, $uids[0]);
                $firstMonth = ($first->date ?? $absoluteStart)->copy()->startOfMonth();
                $firstMonth = $firstMonth->max($absoluteStart)->min($cutoffEnd->copy()->subDay()->startOfMonth());

                // A single prefix window guarantees coverage even when messages
                // were moved and UID order differs from their internal date.
                if ($absoluteStart->lt($firstMonth)) {
                    $rows[] = $this->windowRow($installation, $backfill, $mailbox, $absoluteStart, $firstMonth, $state->uidValidity, max($uids));
                }

                for ($start = $firstMonth->copy(); $start->lt($cutoffEnd); $start->addMonth()) {
                    $end = $start->copy()->addMonth()->min($cutoffEnd);
                    $rows[] = $this->windowRow($installation, $backfill, $mailbox, $start, $end, $state->uidValidity, max($uids));
                }
            }

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
        } finally {
            $client->close();
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
