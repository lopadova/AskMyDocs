<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use RuntimeException;

/** Resolves credentials/config, then creates a bulk client via the shared factory. */
final class ImapBackfillClientProvider implements ImapBackfillClientProviderContract
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ImapClientFactoryInterface $factory,
    ) {}

    public function forInstallation(ConnectorInstallation $installation): ImapBackfillClient
    {
        $connector = $this->registry->get('imap');
        if (! $connector instanceof BaseConnector) {
            throw new RuntimeException('The IMAP connector is not installed.');
        }

        $secret = (string) ($connector->refreshTokenIfExpired($installation->id) ?? '');
        if ($secret === '') {
            throw new RuntimeException('The IMAP credential is missing or expired.');
        }

        $config = (array) ($installation->config_json ?? []);
        $authMode = (string) ($config['auth_mode'] ?? 'basic');
        $connection = (array) ($config['connection'] ?? []);

        // Never send a freshly minted Microsoft app-only token to a configurable
        // host. This mirrors the connector package's own security boundary.
        if ($authMode === 'xoauth2_client_credentials') {
            $provider = (array) config('connectors.providers.imap.client_credentials.microsoft', []);
            $connection['host'] = (string) ($provider['imap_host'] ?? 'outlook.office365.com');
            $connection['port'] = (int) ($provider['imap_port'] ?? 993);
            $connection['encryption'] = (string) ($provider['imap_encryption'] ?? 'ssl');
        }

        if (! $this->factory instanceof ImapBackfillClientFactory) {
            throw new RuntimeException('The resolved IMAP factory does not support durable backfills.');
        }

        return $this->factory->makeBackfill($connection, $secret, $authMode);
    }
}
