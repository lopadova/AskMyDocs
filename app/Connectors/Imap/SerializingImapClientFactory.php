<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use Illuminate\Contracts\Cache\LockProvider;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;

/**
 * Wraps the real {@see ImapClientFactoryInterface} so every IMAP client it produces
 * is a {@see SerializingImapClient} that holds a per-mailbox lock for the lifetime
 * of its connection. Registered host-side via `$app->extend(...)` in
 * AppServiceProvider, so it covers EVERY connection path (sync, health, OAuth ping,
 * test-fetch, folder picker) without touching the connector package.
 *
 * A connection whose `host`/`username` can't form a mailbox key (misconfigured row)
 * is returned undecorated — there is nothing meaningful to serialize on.
 */
final class SerializingImapClientFactory implements ImapClientFactoryInterface
{
    public function __construct(
        private readonly ImapClientFactoryInterface $inner,
        private readonly LockProvider $lockProvider,
        private readonly int $waitSeconds,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * @param  array<string,mixed>  $connection
     */
    public function make(array $connection, string $secret, string $authMode): ImapClientInterface
    {
        $client = $this->inner->make($connection, $secret, $authMode);

        $key = MailboxLockKey::forConnection($connection);
        if ($key === null) {
            return $client;
        }

        return new SerializingImapClient(
            $client,
            $this->lockProvider,
            $key,
            $this->waitSeconds,
            $this->ttlSeconds,
        );
    }
}
