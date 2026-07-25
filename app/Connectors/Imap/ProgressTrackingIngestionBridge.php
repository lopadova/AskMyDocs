<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * Transparent host-ingestion decorator that acknowledges an IMAP document only
 * after the real bridge returned successfully.
 */
final class ProgressTrackingIngestionBridge implements ConnectorIngestionContract
{
    public function __construct(
        private readonly ConnectorIngestionContract $inner,
        private readonly ImapSyncProgressContext $progress,
    ) {}

    public function dispatchIngestion(
        string $projectKey,
        string $relativePath,
        string $disk,
        string $title,
        array $metadata,
        string $mimeType,
        string $tenantId,
    ): void {
        $this->inner->dispatchIngestion(
            $projectKey,
            $relativePath,
            $disk,
            $title,
            $metadata,
            $mimeType,
            $tenantId,
        );

        $this->progress->recordSuccessfulDispatch($metadata, $tenantId);
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        return $this->inner->resolveKbSourcePath($relativePath);
    }

    public function redactContent(string $content): string
    {
        return $this->inner->redactContent($content);
    }

    public function emitAudit(
        string $connectorKey,
        string $eventType,
        ?int $installationId = null,
        ?array $metadata = null,
    ): void {
        $this->inner->emitAudit($connectorKey, $eventType, $installationId, $metadata);
    }

    public function softDeleteByRemoteId(
        ConnectorInstallation $installation,
        string $metadataKey,
        string $remoteId,
    ): bool {
        return $this->inner->softDeleteByRemoteId($installation, $metadataKey, $remoteId);
    }
}
