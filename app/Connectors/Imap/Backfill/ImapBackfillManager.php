<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use App\Jobs\Imap\DiscoverImapBackfillJob;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ImapBackfillManager
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function start(int $installationId): ImapBackfill
    {
        if (! Schema::hasTable('imap_backfills')) {
            throw new \RuntimeException('Run database migrations before starting an IMAP backfill.');
        }

        $tenantId = $this->tenantContext->current();
        $installation = ConnectorInstallation::query()
            ->forTenant($tenantId)
            ->where('id', $installationId)
            ->where('connector_name', 'imap')
            ->first();
        if ($installation === null) {
            throw new NotFoundHttpException('IMAP installation not found.');
        }

        $backfill = DB::transaction(function () use ($tenantId, $installation): ImapBackfill {
            $active = ImapBackfill::query()
                ->forTenant($tenantId)
                ->where('connector_installation_id', $installation->id)
                ->whereIn('status', ImapBackfill::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if ($active !== null) {
                return $active;
            }

            $settings = (array) ($installation->config_json ?? []);
            // This endpoint means ALL mail in the selected mail folders. Content
            // rendering and attachment policy remain configurable, but message
            // filters must not silently turn 128k remote messages into a subset.
            $settings['date_window_days'] = 0;
            $settings['only_unseen'] = false;
            $settings['only_flagged'] = false;
            $settings['skip_auto_generated'] = false;
            $settings['senders'] = ['include' => [], 'exclude' => []];
            $settings['recipients'] = ['include' => [], 'exclude' => []];
            $settings['subject'] = ['include_keywords' => [], 'exclude_keywords' => []];

            return ImapBackfill::query()->create([
                'tenant_id' => $tenantId,
                'connector_installation_id' => $installation->id,
                'status' => ImapBackfill::STATUS_DISCOVERING,
                'settings_json' => $settings,
                'batch_size' => max(1, (int) config('connectors.imap.backfill.batch_size', 100)),
                'cutoff_at' => now(),
                'started_at' => now(),
                'heartbeat_at' => now(),
            ]);
        });

        if ($backfill->wasRecentlyCreated && $backfill->status === ImapBackfill::STATUS_DISCOVERING) {
            DiscoverImapBackfillJob::dispatch($backfill->id, $tenantId)
                ->onQueue((string) config('connectors.imap.backfill.queue', 'connectors'));
        }

        return $backfill->fresh();
    }

    public function hasCompletedBackfill(int $installationId): bool
    {
        if (! Schema::hasTable('imap_backfills')) {
            return false;
        }

        return ImapBackfill::query()
            ->forTenant($this->tenantContext->current())
            ->where('connector_installation_id', $installationId)
            ->where('status', ImapBackfill::STATUS_COMPLETED)
            ->exists();
    }

    /** @return array<string,mixed>|null */
    public function status(int $installationId): ?array
    {
        $tenantId = $this->tenantContext->current();
        $backfill = ImapBackfill::query()
            ->forTenant($tenantId)
            ->where('connector_installation_id', $installationId)
            ->latest('id')
            ->first();
        if ($backfill === null) {
            return null;
        }

        $current = ImapBackfillWindow::query()
            ->forTenant($tenantId)
            ->where('imap_backfill_id', $backfill->id)
            ->whereIn('status', [ImapBackfillWindow::STATUS_QUEUED, ImapBackfillWindow::STATUS_RUNNING])
            ->orderBy('id')
            ->first();
        $lastError = ImapBackfillWindow::query()
            ->forTenant($tenantId)
            ->where('imap_backfill_id', $backfill->id)
            ->whereNotNull('error_json')
            ->latest('updated_at')
            ->first();

        return [
            'id' => $backfill->id,
            'installation_id' => $backfill->connector_installation_id,
            'status' => $backfill->status,
            'total_messages' => $backfill->total_messages,
            'processed_messages' => $backfill->processed_messages,
            'dispatched_documents' => $backfill->dispatched_documents,
            'total_windows' => $backfill->total_windows,
            'completed_windows' => $backfill->completed_windows,
            'progress_percent' => $backfill->total_messages > 0
                ? round(($backfill->processed_messages / $backfill->total_messages) * 100, 2)
                : ($backfill->status === ImapBackfill::STATUS_COMPLETED ? 100.0 : 0.0),
            'batch_size' => $backfill->batch_size,
            'started_at' => $backfill->started_at?->toIso8601String(),
            'completed_at' => $backfill->completed_at?->toIso8601String(),
            'heartbeat_at' => $backfill->heartbeat_at?->toIso8601String(),
            'current_window' => $current === null ? null : [
                'mailbox' => $current->mailbox,
                'start' => $current->window_start->toDateString(),
                'end' => $current->window_end->toDateString(),
                'processed_messages' => $current->processed_messages,
                'expected_messages' => $current->expected_messages,
                'last_uid' => $current->last_uid,
            ],
            'last_error' => $lastError?->error_json ?? $backfill->error_json,
        ];
    }
}
