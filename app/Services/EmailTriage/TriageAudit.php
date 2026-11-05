<?php

declare(strict_types=1);

namespace App\Services\EmailTriage;

use App\Models\KbCanonicalAudit;
use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/** Content-free administrative audit, alongside immutable profiles/evaluations. */
final class TriageAudit
{
    public function record(ConnectorInstallation $installation, string $event, array $identifiers): void
    {
        $context = ['tenant_id' => $installation->tenant_id, 'installation_id' => $installation->id] + $identifiers;
        KbCanonicalAudit::create([
            'tenant_id' => $installation->tenant_id, 'project_key' => $installation->project_key ?: 'default',
            'event_type' => 'email_triage_'.$event, 'actor' => auth()->id() ? 'user:'.auth()->id() : 'system',
            'metadata_json' => $context,
        ]);
        Log::info('Email triage '.$event, $context);
    }
}
