<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

interface ConnectorInstallationAction
{
    public function descriptor(ConnectorInstallation $installation): array;

    public function status(ConnectorInstallation $installation): ?array;

    public function execute(ConnectorInstallation $installation): array;
}
