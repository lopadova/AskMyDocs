<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorFreshdesk\Exceptions\InvalidHistoryStartDate;
use Padosoft\AskMyDocsConnectorFreshdesk\Support\Features;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncManager;

final readonly class FreshdeskHistoricalImportAction implements ConnectorInstallationAction
{
    public function __construct(private SyncManager $sync) {}

    public function descriptor(ConnectorInstallation $installation): array
    {
        $startDate = data_get($installation->config_json, 'history.start_date');

        return ['label' => $startDate ? 'Importa storico dalla data' : 'Prendi tutto',
            'description' => ($startDate ? 'Importa i ticket aggiornati dal '.$startDate.' (UTC).' : 'Importa tutto lo storico enumerabile dalle API.')
                .' La finestra ordinaria resta invariata. Gli articoli pubblicati non sono filtrati per data. Salva le impostazioni prima di avviare; un import incompleto riprende il proprio periodo originale.',
            'enabled' => Features::ingestion($installation) && Schema::hasTable('freshdesk_sync_runs') && in_array($installation->status, ['active', 'errored'], true)];
    }

    public function status(ConnectorInstallation $installation): ?array
    {
        return $this->sync->latest($installation)?->summary();
    }

    public function execute(ConnectorInstallation $installation): array
    {
        try {
            return $this->sync->start($installation, history: true)->summary();
        } catch (InvalidHistoryStartDate $exception) {
            throw ValidationException::withMessages(['settings.history.start_date' => $exception->getMessage()]);
        }
    }
}
