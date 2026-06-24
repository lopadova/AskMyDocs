<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Live folder discovery for an IMAP installation — the data source behind the
 * admin "connection settings" folder picker.
 *
 * Host-side by design (v8.24): the connector's own client builder is `protected`,
 * so instead of bumping the package we reuse its PUBLIC seams — the bound
 * {@see ImapClientFactoryInterface} + the {@see OAuthCredentialVault} secret +
 * the stored `config_json.connection` — to open a client and list its mailboxes.
 * The shape returned is exactly what `config_json.folders.include` whitelists
 * (the verbatim, case-sensitive folder paths from
 * `WebklexImapClient::listMailboxes()`), so a picked value round-trips 1:1.
 *
 * R30 — every lookup is tenant-scoped; a cross-tenant id 404s.
 * R14 — an unreachable server / rejected credentials raise
 * {@see ImapFolderListingException} (→ 503), never a misleading empty 200.
 */
final class ImapFolderListingService
{
    private const CONNECTOR = 'imap';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ImapClientFactoryInterface $factory,
        private readonly OAuthCredentialVault $vault,
    ) {}

    /**
     * The live mailbox/label paths for an IMAP installation.
     *
     * @return list<string>
     *
     * @throws NotFoundHttpException        when the installation is absent / cross-tenant / not IMAP
     * @throws ImapFolderListingException   when the IMAP server cannot be reached
     */
    public function listFolders(int $installationId): array
    {
        $installation = ConnectorInstallation::query()
            ->where('id', $installationId)
            ->where('tenant_id', $this->tenantContext->current())
            ->first();

        if ($installation === null) {
            throw new NotFoundHttpException("Installation {$installationId} not found.");
        }

        if ($installation->connector_name !== self::CONNECTOR) {
            // Folder discovery is IMAP-specific; other connectors have no notion
            // of mailbox paths to whitelist.
            throw new NotFoundHttpException(
                "Connector '{$installation->connector_name}' does not expose IMAP folders.",
            );
        }

        $config = (array) ($installation->config_json ?? []);
        $connection = (array) ($config['connection'] ?? []);
        $authMode = (string) ($config['auth_mode'] ?? 'basic');
        $secret = (string) ($this->vault->getAccessToken($installation->id) ?? '');

        try {
            $client = $this->factory->make($connection, $secret, $authMode);
            $folders = $client->listMailboxes();
            $client->close();
        } catch (Throwable $e) {
            // R14 — surface "couldn't reach the mailbox" as a distinct failure;
            // never let it look like an empty-but-successful list.
            throw new ImapFolderListingException(
                "Impossibile elencare le cartelle IMAP: {$e->getMessage()}",
                previous: $e,
            );
        }

        return array_values(array_map('strval', $folders));
    }
}
