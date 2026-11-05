<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Connectors\Imap\MailboxLockKey;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/** Safely discard one stuck IMAP backfill campaign without clearing shared queues. */
final class ResetImapBackfillCommand extends Command
{
    protected $signature = 'connectors:imap-backfill:reset
        {tenant : Exact tenant slug owning the backfill}
        {backfill : Exact imap_backfills id to discard}
        {--wait=15 : Seconds to wait for the queue and mailbox locks}
        {--force : Skip the interactive confirmation}';

    protected $description = 'Discard one tenant-scoped IMAP backfill and its windows so it can be started cleanly.';

    public function handle(): int
    {
        $tenantId = trim((string) $this->argument('tenant'));
        $backfillId = filter_var($this->argument('backfill'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $waitSeconds = filter_var($this->option('wait'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 900],
        ]);

        if (! preg_match('/^[a-z0-9_-]{1,50}$/', $tenantId)) {
            $this->error('Invalid tenant slug. Use lowercase letters, numbers, underscores or hyphens.');

            return self::FAILURE;
        }

        if ($backfillId === false) {
            $this->error('The backfill id must be a positive integer.');

            return self::FAILURE;
        }

        if ($waitSeconds === false) {
            $this->error('--wait must be an integer between 0 and 900 seconds.');

            return self::FAILURE;
        }

        $backfill = ImapBackfill::query()
            ->forTenant($tenantId)
            ->whereKey($backfillId)
            ->first();
        if ($backfill === null) {
            $this->error("IMAP backfill {$backfillId} does not exist in tenant '{$tenantId}'.");

            return self::FAILURE;
        }

        $installation = ConnectorInstallation::query()
            ->forTenant($tenantId)
            ->whereKey($backfill->connector_installation_id)
            ->where('connector_name', 'imap')
            ->first();
        if ($installation === null) {
            $this->error('The tenant-scoped IMAP installation for this backfill no longer exists.');

            return self::FAILURE;
        }

        $mailboxLockKey = MailboxLockKey::forInstallation($installation);
        if ($mailboxLockKey === null) {
            $this->error('The IMAP installation has no valid host/username lock identity; refusing an unsafe reset.');

            return self::FAILURE;
        }

        $windowCount = ImapBackfillWindow::query()
            ->forTenant($tenantId)
            ->where('imap_backfill_id', $backfillId)
            ->count();

        $this->warn("Backfill {$backfillId} for tenant '{$tenantId}' will be permanently discarded.");
        $this->line("Installation: {$installation->id}; status: {$backfill->status}; windows: {$windowCount}.");
        $this->line('Already-ingested knowledge documents are preserved. Pending jobs for this id will become no-ops.');

        if (! $this->option('force') && ! $this->confirm('Discard this IMAP backfill now?', false)) {
            $this->info('IMAP backfill reset cancelled; no data was changed.');

            return self::SUCCESS;
        }

        $queueLock = null;
        $mailboxLock = null;
        $ttlSeconds = max(60, (int) config('connectors.imap.mailbox_lock.ttl_seconds', 700));
        $queueLockKey = (new WithoutOverlapping($mailboxLockKey))
            ->shared()
            ->getLockKey($this);

        try {
            $queueLock = $this->acquire($queueLockKey, $ttlSeconds, $waitSeconds);
            $mailboxLock = $this->acquire($mailboxLockKey, $ttlSeconds, $waitSeconds);

            $deletedWindows = 0;
            DB::transaction(function () use ($tenantId, $backfillId, &$deletedWindows): void {
                $lockedBackfill = ImapBackfill::query()
                    ->forTenant($tenantId)
                    ->whereKey($backfillId)
                    ->lockForUpdate()
                    ->first();
                if ($lockedBackfill === null) {
                    throw (new ModelNotFoundException)->setModel(ImapBackfill::class, [$backfillId]);
                }

                $deletedWindows = ImapBackfillWindow::query()
                    ->forTenant($tenantId)
                    ->where('imap_backfill_id', $backfillId)
                    ->delete();
                $lockedBackfill->delete();
            });
        } catch (LockTimeoutException) {
            $this->error("The mailbox is still busy after {$waitSeconds} second(s); no data was changed.");
            $this->line('Pause the connectors queue or wait for the active job to finish, then run this command again.');

            return self::FAILURE;
        } catch (ModelNotFoundException) {
            $this->error('The backfill disappeared before the reset could start; no additional data was changed.');

            return self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Unable to reset the IMAP backfill safely: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $mailboxLock?->release();
            $queueLock?->release();
        }

        Log::notice('Tenant-scoped IMAP backfill discarded from CLI.', [
            'tenant_id' => $tenantId,
            'backfill_id' => $backfillId,
            'installation_id' => $installation->id,
            'windows_deleted' => $deletedWindows,
            'mailbox_lock_key' => $mailboxLockKey,
        ]);

        $this->info("Backfill {$backfillId} was discarded; {$deletedWindows} window(s) removed.");
        $this->line('Start the full-history import again to create a new clean campaign.');

        return self::SUCCESS;
    }

    private function acquire(string $key, int $ttlSeconds, int $waitSeconds): Lock
    {
        $lock = Cache::lock($key, $ttlSeconds);
        $lock->block($waitSeconds);

        return $lock;
    }
}
