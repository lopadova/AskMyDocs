<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\WebklexImapClient;
use Webklex\PHPIMAP\ClientManager;

/**
 * Adds the host-only bulk surface to the package factory without replacing it.
 * Later reconnect/serialization decorators wrap both make() methods, so normal
 * syncs and backfills share one resolved factory and one mailbox lock key.
 */
final class ImapBackfillClientFactoryAdapter implements ImapClientFactoryInterface, ImapBackfillClientFactory
{
    public function __construct(private readonly ImapClientFactoryInterface $inner) {}

    public function make(array $connection, string $secret, string $authMode): ImapClientInterface
    {
        return $this->inner->make($connection, $secret, $authMode);
    }

    public function makeBackfill(array $connection, string $secret, string $authMode): ImapBackfillClient
    {
        $raw = (new ClientManager)->make([
            'host' => (string) ($connection['host'] ?? ''),
            'port' => (int) ($connection['port'] ?? 993),
            'encryption' => (string) ($connection['encryption'] ?? 'ssl'),
            'validate_cert' => (bool) ($connection['validate_cert'] ?? true),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => $secret,
            'rfc' => 'BODY',
            'authentication' => in_array($authMode, ['xoauth2', 'xoauth2_client_credentials'], true)
                ? 'oauth'
                : null,
        ]);

        return new ImapBackfillMailboxClient($raw, new WebklexImapClient($raw));
    }
}
