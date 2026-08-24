<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use App\Jobs\Imap\DiscoverImapBackfillJob;
use App\Jobs\Imap\PumpImapBackfillJob;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ImapBackfillManager
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function start(int $installationId): ImapBackfill
    {
        if (! $this->isEnabled()) {
            throw new ConflictHttpException('Full-history IMAP imports are disabled by deployment configuration.');
        }

        if (! Schema::hasTable('imap_backfills')) {
            throw new \RuntimeException('Run database migrations before starting an IMAP backfill.');
        }

        $tenantId = $this->tenantContext->current();
        $dispatch = null;
        $backfill = DB::transaction(function () use ($tenantId, $installationId, &$dispatch): ImapBackfill {
            // The installation row always exists before a campaign and is unique,
            // so it is the serialization point for concurrent empty-set starts.
            $installation = ConnectorInstallation::query()
                ->forTenant($tenantId)
                ->where('id', $installationId)
                ->where('connector_name', 'imap')
                ->lockForUpdate()
                ->first();
            if ($installation === null) {
                throw new NotFoundHttpException('IMAP installation not found.');
            }
            if ($installation->status !== ConnectorInstallation::STATUS_ACTIVE) {
                throw new UnprocessableEntityHttpException(
                    'The IMAP account must be active before starting a full-history import.',
                );
            }

            $active = ImapBackfill::query()
                ->forTenant($tenantId)
                ->where('connector_installation_id', $installation->id)
                ->whereIn('status', ImapBackfill::ACTIVE_STATUSES)
                ->latest('id')
                ->first();
            if ($active !== null) {
                return $active;
            }

            // A terminal queue failure must not discard a large mailbox's
            // durable progress. The installation lock serializes concurrent
            // operator retries, while the latest-campaign check prevents an
            // older failed campaign from being revived after a newer one.
            $latest = ImapBackfill::query()
                ->forTenant($tenantId)
                ->where('connector_installation_id', $installation->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if (
                $latest?->status === ImapBackfill::STATUS_FAILED
                && ! $this->requiresFreshSnapshot($latest)
            ) {
                $windowQuery = ImapBackfillWindow::query()
                    ->forTenant($tenantId)
                    ->where('imap_backfill_id', $latest->id);
                $totalWindows = (clone $windowQuery)->count();

                if ($totalWindows === 0) {
                    // Discovery persists its window set atomically. With no
                    // windows there is no UID checkpoint to preserve, so retry
                    // discovery on the SAME campaign and cutoff snapshot.
                    $latest->forceFill([
                        'status' => ImapBackfill::STATUS_DISCOVERING,
                        'completed_at' => null,
                        'heartbeat_at' => now(),
                        'error_json' => null,
                    ])->save();
                    $dispatch = 'discover';

                    return $latest;
                }

                // Resume every incomplete window immediately. Completed
                // windows and every window's last_uid/processed counters stay
                // untouched, so the first batch continues after the last
                // confirmed UID rather than downloading history again.
                (clone $windowQuery)
                    ->where('status', '!=', ImapBackfillWindow::STATUS_COMPLETED)
                    ->update([
                        'status' => ImapBackfillWindow::STATUS_PENDING,
                        'finished_at' => null,
                        'heartbeat_at' => now(),
                        'next_attempt_at' => now(),
                        'error_json' => null,
                        'updated_at' => now(),
                    ]);

                // Aggregate counters can lag their window transaction if a
                // worker died at a boundary. Rebuild the campaign projection
                // from the durable source-of-truth before exposing progress.
                $totals = (clone $windowQuery)
                    ->selectRaw('COALESCE(SUM(processed_messages), 0) AS processed_messages')
                    ->selectRaw('COALESCE(SUM(dispatched_documents), 0) AS dispatched_documents')
                    ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_windows', [
                        ImapBackfillWindow::STATUS_COMPLETED,
                    ])
                    ->firstOrFail();

                $latest->forceFill([
                    'status' => ImapBackfill::STATUS_RUNNING,
                    'processed_messages' => (int) $totals->processed_messages,
                    'dispatched_documents' => (int) $totals->dispatched_documents,
                    'total_windows' => $totalWindows,
                    'completed_windows' => (int) $totals->completed_windows,
                    'completed_at' => null,
                    'heartbeat_at' => now(),
                    'error_json' => null,
                ])->save();
                $dispatch = 'pump';

                return $latest;
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

            $created = ImapBackfill::query()->create([
                'tenant_id' => $tenantId,
                'connector_installation_id' => $installation->id,
                'status' => ImapBackfill::STATUS_DISCOVERING,
                'settings_json' => $settings,
                'batch_size' => max(1, (int) config('connectors.imap.backfill.batch_size', 100)),
                'cutoff_at' => now(),
                'started_at' => now(),
                'heartbeat_at' => now(),
            ]);
            $dispatch = 'discover';

            return $created;
        });

        if ($dispatch === 'discover') {
            DiscoverImapBackfillJob::dispatch($backfill->id, $tenantId)
                ->onQueue((string) config('connectors.imap.backfill.queue', 'connectors'));
        } elseif ($dispatch === 'pump') {
            PumpImapBackfillJob::dispatch($backfill->id, $tenantId)
                ->onQueue((string) config('connectors.imap.backfill.queue', 'connectors'));
        }

        return $backfill->fresh();
    }

    public function isEnabled(): bool
    {
        return config('connectors.imap.backfill.enabled', true) === true;
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
        $installation = ConnectorInstallation::query()
            ->forTenant($tenantId)
            ->where('id', $installationId)
            ->where('connector_name', 'imap')
            ->first();
        if ($installation === null) {
            throw new NotFoundHttpException('IMAP installation not found.');
        }

        if (! Schema::hasTable('imap_backfills')) {
            return null;
        }

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

        $progressPercent = match (true) {
            $backfill->status === ImapBackfill::STATUS_COMPLETED => 100.0,
            $backfill->total_messages > 0 => max(0.0, min(
                100.0,
                round(($backfill->processed_messages / $backfill->total_messages) * 100, 2),
            )),
            default => 0.0,
        };

        return [
            'id' => $backfill->id,
            'installation_id' => $backfill->connector_installation_id,
            'status' => $backfill->status,
            'retry_mode' => $backfill->status === ImapBackfill::STATUS_FAILED
                ? ($this->requiresFreshSnapshot($backfill) ? 'restart' : 'resume')
                : null,
            'total_messages' => $backfill->total_messages,
            'processed_messages' => $backfill->processed_messages,
            'dispatched_documents' => $backfill->dispatched_documents,
            'total_windows' => $backfill->total_windows,
            'completed_windows' => $backfill->completed_windows,
            'progress_percent' => $progressPercent,
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

    private function requiresFreshSnapshot(ImapBackfill $backfill): bool
    {
        $message = data_get($backfill->error_json, 'message');

        return is_string($message)
            && Str::contains($message, 'UIDVALIDITY changed', ignoreCase: true);
    }
}
