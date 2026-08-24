<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use App\Connectors\Imap\Backfill\ImapBackfillClient;
use App\Connectors\Imap\Backfill\ImapBackfillClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;

/**
 * Wraps the real {@see ImapClientFactoryInterface} so every IMAP client it produces
 * is a {@see ReconnectingImapClient} that absorbs a transient transport drop with one
 * close-and-retry. Registered host-side via `$app->extend(...)` in AppServiceProvider
 * BEFORE the serializer, so the resulting client is
 * `SerializingImapClient(ReconnectingImapClient(realClient))` — the reconnect happens
 * inside the per-mailbox lock. Covers EVERY connection path (sync, health, OAuth ping,
 * folder picker, test-fetch) without touching the connector package.
 *
 * Unlike serialization, reconnection has nothing to key on — it wraps every client
 * unconditionally.
 */
final class ReconnectingImapClientFactory implements ImapClientFactoryInterface, ImapBackfillClientFactory
{
    public function __construct(
        private readonly ImapClientFactoryInterface $inner,
        private readonly int $maxAttempts,
        private readonly int $retryDelayMs,
    ) {}

    /**
     * @param  array<string,mixed>  $connection
     */
    public function make(array $connection, string $secret, string $authMode): ImapClientInterface
    {
        return new ReconnectingImapClient(
            $this->inner->make($connection, $secret, $authMode),
            $this->maxAttempts,
            $this->retryDelayMs,
        );
    }

    public function makeBackfill(array $connection, string $secret, string $authMode): ImapBackfillClient
    {
        if (! $this->inner instanceof ImapBackfillClientFactory) {
            throw new \RuntimeException('The inner IMAP factory does not support durable backfills.');
        }

        return new ReconnectingImapBackfillClient(
            $this->inner->makeBackfill($connection, $secret, $authMode),
            $this->maxAttempts,
            $this->retryDelayMs,
        );
    }
}
