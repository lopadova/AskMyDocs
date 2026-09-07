<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Connectors\Imap\MailboxLockKey;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use Illuminate\Cache\RedisStore;
use Illuminate\Console\Command;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use RuntimeException;
use Throwable;

/** Read-only snapshot: never acquire/release a lock, dispatch work or connect to IMAP. */
final class DiagnoseImapCommand extends Command
{
    protected $signature = 'connectors:imap:diagnose
        {tenant : Exact tenant slug}
        {backfill? : Exact backfill id; omit to discover the tenant IMAP installation}
        {--installation= : Inspect this IMAP installation instead of a backfill id}';

    protected $description = 'Inspect IMAP backfill state, queue configuration and Redis mailbox/overlap lock TTLs without changing data.';

    public function handle(): int
    {
        $tenantId = trim((string) $this->argument('tenant'));
        if (! preg_match('/^[a-z0-9_-]{1,50}$/', $tenantId)) {
            $this->error('Invalid tenant slug. Use lowercase letters, numbers, underscores or hyphens.');

            return self::FAILURE;
        }

        $backfillArgument = $this->argument('backfill');
        $installationOption = $this->option('installation');
        if ($backfillArgument !== null && $installationOption !== null) {
            $this->error('Provide either a backfill id or --installation=ID, not both.');

            return self::FAILURE;
        }

        if ($backfillArgument === null && $installationOption === null) {
            $installations = $this->availableInstallations($tenantId);
            if ($installations->count() !== 1) {
                $this->showInstallationChoices($tenantId, $installations);

                return self::FAILURE;
            }

            $installationOption = $installations->first()->id;
            $this->line("Automatically selected the only IMAP installation in '{$tenantId}': {$installationOption}.");
        }

        $id = filter_var($backfillArgument ?? $installationOption, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($id === false) {
            $this->error('The selected id must be a positive integer.');

            return self::FAILURE;
        }

        $backfill = null;
        if ($backfillArgument !== null) {
            $backfill = ImapBackfill::query()->forTenant($tenantId)->find($id);
            if ($backfill === null) {
                $this->error("IMAP backfill {$id} does not exist in tenant '{$tenantId}'.");
                $this->line('If it was reset, use --installation=ID with the IMAP installation id.');
                $this->showInstallationChoices($tenantId, $this->availableInstallations($tenantId));

                return self::FAILURE;
            }
        }

        $installation = ConnectorInstallation::query()
            ->forTenant($tenantId)
            ->where('connector_name', 'imap')
            ->find($backfill?->connector_installation_id ?? $id);
        if ($installation === null) {
            $this->error("The selected IMAP installation does not exist in tenant '{$tenantId}'.");

            return self::FAILURE;
        }

        if ($installationOption !== null) {
            $backfill = ImapBackfill::query()
                ->forTenant($tenantId)
                ->where('connector_installation_id', $installation->id)
                ->latest('id')
                ->first();
        }

        $this->info('Read-only IMAP diagnostics (snapshot, not a worker activity check).');
        $this->line('observed_at: '.now()->utc()->toAtomString());
        $this->line("tenant: {$tenantId}");
        $this->line("installation_id: {$installation->id}");
        $this->line("installation_status: {$installation->status}");
        $this->line('backfill_selection: '.($installationOption !== null ? 'latest for installation' : 'explicit id'));
        $this->line('backfill_id: '.($backfill?->id ?? 'none'));
        if ($backfill !== null) {
            $this->line("backfill_status: {$backfill->status}");
            $this->line('backfill_heartbeat_at: '.($backfill->heartbeat_at?->toAtomString() ?? 'none'));
            $this->line('backfill_windows: '.ImapBackfillWindow::query()
                ->forTenant($tenantId)
                ->where('imap_backfill_id', $backfill->id)
                ->count());
        }

        // Only allowlisted configuration; never dump credentials, cache URLs or owner tokens.
        $queueConnection = (string) config('queue.default');
        $cacheStore = (string) config('cache.default');
        $this->line('queue_connection: '.$queueConnection);
        $this->line('queue_driver: '.config("queue.connections.{$queueConnection}.driver", 'unknown'));
        $this->line('backfill_queue: '.config('connectors.imap.backfill.queue', 'connectors'));
        $this->line('cache_store: '.$cacheStore);
        $this->line('cache_driver: '.config("cache.stores.{$cacheStore}.driver", 'unknown'));
        $this->line('serialize_connections: '.(config('connectors.imap.serialize_connections', true) ? 'true' : 'false'));
        $this->line('configured_lock_wait_seconds: '.config('connectors.imap.mailbox_lock.wait_seconds', 15));
        $this->line('configured_lock_ttl_seconds: '.config('connectors.imap.mailbox_lock.ttl_seconds', 700));

        $mailboxKey = MailboxLockKey::forInstallation($installation);
        if ($mailboxKey === null) {
            $this->error('No valid host/username mailbox lock identity; lock state is unknown.');

            return self::FAILURE;
        }

        $queueKey = (new WithoutOverlapping($mailboxKey))->shared()->getLockKey($this);
        $this->line('mailbox_lock_key: '.$mailboxKey);
        $this->line('queue_overlap_lock_key: '.$queueKey);

        try {
            $store = Cache::getStore();
            if (! $store instanceof RedisStore) {
                $this->error('Lock TTL inspection requires a Redis cache store; lock state is unknown.');

                return self::FAILURE;
            }

            // Match RedisStore::lock(): use its LOCK connection and cache prefix.
            // The Redis client applies any connection-level prefix itself.
            $redis = $store->lockConnection();
            $mailboxState = $this->describeTtl($redis->ttl($store->getPrefix().$mailboxKey));
            $queueState = $this->describeTtl($redis->ttl($store->getPrefix().$queueKey));
        } catch (Throwable $exception) {
            // Connection exception messages can contain credentials/Redis URLs.
            $this->error('Unable to read Redis lock TTLs ('.$exception::class.'); lock state is unknown.');

            return self::FAILURE;
        }

        $this->line('mailbox_lock: '.$mailboxState);
        $this->line('queue_overlap_lock: '.$queueState);
        $this->line('TTL >= 0: present, seconds until expiry; -2: absent; -1: present without expiry.');
        $this->line('A present lock does not identify its worker or prove it is still running.');
        $this->line('No locks, jobs or backfill data were changed. Run again to compare TTLs.');

        return self::SUCCESS;
    }

    /** @return Collection<int, ConnectorInstallation> */
    private function availableInstallations(string $tenantId): Collection
    {
        return ConnectorInstallation::query()
            ->forTenant($tenantId)
            ->where('connector_name', 'imap')
            ->orderBy('id')
            ->get(['id', 'label', 'status']);
    }

    /** @param Collection<int, ConnectorInstallation> $installations */
    private function showInstallationChoices(string $tenantId, Collection $installations): void
    {
        if ($installations->isEmpty()) {
            $this->error("No IMAP installations exist in tenant '{$tenantId}'; lock state was not inspected.");

            return;
        }

        $this->line("Available IMAP installations in '{$tenantId}' (no lock inspection performed):");
        $this->table(['ID', 'Label', 'Status'], $installations->map(
            static fn (ConnectorInstallation $installation): array => [
                $installation->id, $installation->label, $installation->status,
            ],
        )->all());
        $this->line('Choose an installation and run:');
        foreach ($installations as $installation) {
            $this->line("php artisan connectors:imap:diagnose {$tenantId} --installation={$installation->id}");
        }
    }

    private function describeTtl(mixed $ttl): string
    {
        if (! is_int($ttl) || $ttl < -2) {
            throw new RuntimeException('Unexpected Redis TTL response.');
        }

        return match ($ttl) {
            -2 => 'absent (TTL: -2)',
            -1 => 'present without expiry (TTL: -1)',
            default => "present (TTL: {$ttl} seconds)",
        };
    }
}
