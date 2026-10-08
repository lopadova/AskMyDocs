<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use Illuminate\Support\Facades\Schema;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncManager;

final readonly class FreshdeskHistoricalImportAction implements ConnectorInstallationAction
{
    public function __construct(private SyncManager $sync) {}

    public function descriptor(ConnectorInstallation $installation): array
    {
        return ['label' => 'Prendi tutto', 'description' => 'Importa lo storico disponibile una volta, mantenendo il periodo configurato.', 'enabled' => Schema::hasTable('freshdesk_sync_runs') && in_array($installation->status, ['active', 'errored'], true)];
    }

    public function status(ConnectorInstallation $installation): ?array
    {
        return $this->sync->latest($installation)?->summary();
    }

    public function execute(ConnectorInstallation $installation): array
    {
        return $this->sync->start($installation, history: true)->summary();
    }
}
