<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ConnectorInstallationActionRegistry
{
    /** @var array<string,array<string,ConnectorInstallationAction>> */
    private array $actions = [];

    public function register(string $connector, string $key, ConnectorInstallationAction $action): void
    {
        $this->actions[$connector][$key] = $action;
    }

    public function descriptors(ConnectorInstallation $installation): array
    {
        $result = [];
        foreach ($this->actions[$installation->connector_name] ?? [] as $key => $action) {
            $result[] = ['key' => $key] + $action->descriptor($installation);
        }

        return $result;
    }

    public function get(ConnectorInstallation $installation, string $key): ConnectorInstallationAction
    {
        return $this->actions[$installation->connector_name][$key] ?? throw new NotFoundHttpException('Connector action not found.');
    }
}
