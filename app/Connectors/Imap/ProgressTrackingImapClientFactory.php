<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use App\Connectors\Imap\Backfill\ImapBackfillClient;
use App\Connectors\Imap\Backfill\ImapBackfillClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;

/**
 * Adds UID progress observation only while a sync job has activated a session.
 * Health checks, folder discovery and credential tests remain untouched.
 */
final class ProgressTrackingImapClientFactory implements ImapClientFactoryInterface, ImapBackfillClientFactory
{
    public function __construct(
        private readonly ImapClientFactoryInterface $inner,
        private readonly ImapSyncProgressContext $progress,
    ) {}

    /**
     * @param  array<string,mixed>  $connection
     */
    public function make(array $connection, string $secret, string $authMode): ImapClientInterface
    {
        $client = $this->inner->make($connection, $secret, $authMode);

        if (! $this->progress->isActive()) {
            return $client;
        }

        return new ProgressTrackingImapClient($client, $this->progress);
    }

    public function makeBackfill(array $connection, string $secret, string $authMode): ImapBackfillClient
    {
        if (! $this->inner instanceof ImapBackfillClientFactory) {
            throw new \RuntimeException('The inner IMAP factory does not support durable backfills.');
        }

        // Backfills persist their own SQL checkpoint; incremental-sync progress
        // tracking must not observe or mutate this independent lifecycle.
        return $this->inner->makeBackfill($connection, $secret, $authMode);
    }
}
