<?php

declare(strict_types=1);

namespace App\Services\Kb\Provenance;

use Illuminate\Support\Facades\Log;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\DeclaresProvenance;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use Throwable;

/**
 * Resolves the provenance tier a connector declares for one installation.
 *
 * The connector is asked, never inspected by name (R23): a registry lookup
 * plus an `instanceof` check, so a connector added tomorrow participates
 * without a branch here. A connector that does not implement the capability
 * yields null — "no declaration" — which the ingestion path stores as null
 * and readers resolve through `ProvenanceTier::fromStorage()` to the trusted
 * default, preserving today's meaning for every existing document.
 */
final class ProvenanceResolver
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * The tier for this connector + installation, or null when the connector
     * declares nothing.
     *
     * A connector that throws is a bug, and the interesting question is what
     * to do about it here. Letting it propagate would fail the ingestion of a
     * document over a LABEL that this phase does not yet enforce — a
     * disproportionate trade, and one that would take a whole source offline
     * for a defect in three lines of connector code. Swallowing it and
     * returning null would be worse: null reads as trusted, so a connector
     * whose declaration is broken would have its content silently promoted,
     * which is precisely the fail-open this design exists to avoid.
     *
     * So it fails CLOSED and loudly: the document is labelled untrusted, the
     * failure is logged with the connector and installation, and the
     * mislabelling is visible in the corpus read-out rather than hidden. A
     * wrong "untrusted" is recoverable by fixing the connector and
     * re-ingesting; a wrong "trusted" is not noticed at all.
     */
    public function forInstallation(?string $connectorName, ?int $installationId): ?ProvenanceTier
    {
        if ($connectorName === null || $connectorName === '' || $installationId === null) {
            return null;
        }

        $connector = $this->registry->get($connectorName);

        if (! $connector instanceof DeclaresProvenance) {
            return null;
        }

        try {
            return $connector->provenanceTier($installationId);
        } catch (Throwable $e) {
            Log::error('Connector failed to declare provenance; labelling the document untrusted.', [
                'connector' => $connectorName,
                'installation_id' => $installationId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ProvenanceTier::UntrustedExternal;
        }
    }

    /**
     * The tier for a document being ingested, read from the metadata the
     * connector packed.
     *
     * Non-connector ingestion — the CLI walker, the HTTP batch endpoint, the
     * GitHub action — carries no connector key and resolves to null, which is
     * correct: that content was placed there by someone with access to the
     * repository or the API.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function forIngestionMetadata(array $metadata): ?ProvenanceTier
    {
        $connector = $metadata['connector'] ?? null;
        $installationId = $metadata['installation_id'] ?? null;

        return $this->forInstallation(
            is_string($connector) ? $connector : null,
            is_numeric($installationId) ? (int) $installationId : null,
        );
    }
}
