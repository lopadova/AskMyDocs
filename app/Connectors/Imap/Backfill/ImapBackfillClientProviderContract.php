<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

interface ImapBackfillClientProviderContract
{
    public function forInstallation(ConnectorInstallation $installation): ImapBackfillClient;
}
