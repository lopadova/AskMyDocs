<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsFolderDiscovery;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Live folder discovery for a connector installation — the data source behind the
 * admin folder picker.
 *
 * v8.25: delegates to the connector's own
 * {@see SupportsFolderDiscovery::listAvailableFolders()} via the
 * {@see ConnectorRegistry} (R23 — no `if ($name === 'imap')` branch), so the
 * connector owns the upstream client lifecycle (auth, token refresh, connect,
 * close). This REPLACES the v8.24 host-side workaround that rebuilt the IMAP
 * client itself from `ImapClientFactoryInterface` + the vault secret — which used
 * the STORED OAuth token and so failed for an xoauth2 account whose access token
 * had expired. The connector's `makeClient()` refreshes first, so discovery now
 * works in that case too. The endpoint is connector-agnostic: any connector that
 * implements {@see SupportsFolderDiscovery} gets a picker for free.
 *
 * R30 — every lookup is tenant-scoped; a cross-tenant id 404s.
 * R14 — an unreachable source / rejected credentials raise
 * {@see ConnectorFolderListingException} (→ 503), never a misleading empty 200.
 */
final class ConnectorFolderListingService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ConnectorRegistry $registry,
    ) {}

    /**
     * The live container (folder / label) paths for an installation.
     *
     * @return list<string>
     *
     * @throws NotFoundHttpException        when the installation is absent / cross-tenant / the connector has no folder discovery
     * @throws ConnectorFolderListingException   when the source cannot be reached / credentials are rejected
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

        $connector = $this->registry->get($installation->connector_name);

        if (! $connector instanceof SupportsFolderDiscovery) {
            // The connector has no notion of selectable containers to whitelist.
            throw new NotFoundHttpException(
                "Connector '{$installation->connector_name}' does not support folder discovery.",
            );
        }

        try {
            $folders = $connector->listAvailableFolders($installation->id);
        } catch (Throwable $e) {
            // R14 — surface "couldn't reach the source" as a distinct failure;
            // never let it look like an empty-but-successful list.
            throw new ConnectorFolderListingException(
                "Impossibile elencare le cartelle: {$e->getMessage()}",
                previous: $e,
            );
        }

        return array_values(array_map('strval', $folders));
    }
}
