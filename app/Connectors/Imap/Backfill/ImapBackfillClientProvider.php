<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use App\Connectors\Imap\ImapConnectionParameters;
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
        $connection = ImapConnectionParameters::forConfig($config);

        if (! $this->factory instanceof ImapBackfillClientFactory) {
            throw new RuntimeException('The resolved IMAP factory does not support durable backfills.');
        }

        return $this->factory->makeBackfill($connection, $secret, $authMode);
    }
}
