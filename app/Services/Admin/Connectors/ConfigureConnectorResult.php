<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * Outcome of {@see ConfigureConnectorService::configure()}.
 *
 * `redirectTo` is non-null only for the xoauth2 path — the provider authorize
 * URL the browser must visit to finish the flow via the existing
 * `oauth/callback` route. For basic-auth it is null (the installation is already
 * ACTIVE once `configure()` returns).
 */
final readonly class ConfigureConnectorResult
{
    public function __construct(
        public ConnectorInstallation $installation,
        public ?string $redirectTo,
    ) {}
}
