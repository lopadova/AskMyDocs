<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.20 — the SINGLE core (R44) for connector-installation lifecycle + the
 * read surface. Every surface delegates here:
 *   - PHP   : the `connectors:list` Artisan command ({@see summary}) and
 *             `connectors:install` (OAuth/credential creation).
 *   - HTTP  : {@see \App\Http\Controllers\Api\Admin\ConnectorAdminController}.
 *   - MCP   : {@see \App\Mcp\Tools\ConnectorInstallationsTool} ({@see summary}).
 *
 * Credential (form-driven) account CREATION lives in the sibling
 * {@see ConfigureConnectorService} (it owns the secret/vault round-trip); this
 * service owns OAuth-account creation, the read summary, metadata edits and
 * deletion. Both are tenant-scoped (R30) on every query.
 *
 * Multi-account: a tenant connects N accounts per connector, disambiguated by
 * `label`, each optionally bound to a real `project_key` (null = the tenant
 * default). The composite unique is (tenant_id, connector_name, label).
 */
final class ConnectorInstallationService
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Read core: every registered connector with the active tenant's installed
     * ACCOUNTS (a list — v8.20 multi-account). Shared verbatim by the HTTP
     * `index`, the MCP read tool and the `connectors:list` command so the three
     * surfaces never drift.
     *
     * @return list<array<string,mixed>>
     */
    public function summary(): array
    {
        /** @var Collection<string, Collection<int, ConnectorInstallation>> $byConnector */
        $byConnector = ConnectorInstallation::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->orderBy('connector_name')
            ->orderBy('label')
            ->get()
            ->groupBy('connector_name');

        return $this->registry->all()->map(function ($connector) use ($byConnector): array {
            $isCredential = $connector instanceof SupportsCredentialForm;
            $installations = $byConnector->get($connector->key(), collect())
                ->map(fn (ConnectorInstallation $i) => $this->installationArray($i))
                ->values()
                ->all();

            return [
                'key' => $connector->key(),
                'display_name' => $connector->displayName(),
                'icon_url' => $connector->iconUrl(),
                'oauth_scopes' => $connector->oauthScopes(),
                'auth_kind' => $isCredential ? 'credential' : 'oauth',
                'credential_form_schema' => $isCredential ? $connector->credentialFormSchema() : null,
                // v8.20 — a LIST of accounts (was a single nullable installation).
                'installations' => $installations,
                // Back-compat (R27 additive): the pre-v8.20 single-installation
                // shape — the first account or null. Lets the not-yet-migrated FE
                // keep rendering until PR2 switches it to `installations`; remove
                // it there.
                'installation' => $installations[0] ?? null,
            ];
        })->values()->all();
    }

    /**
     * The canonical serialized shape of ONE installation, shared by
     * {@see summary} and {@see \App\Http\Resources\Admin\ConnectorInstallationResource}.
     * Keep the two in lockstep (R27 — additive only).
     *
     * @return array<string,mixed>
     */
    public function installationArray(ConnectorInstallation $i): array
    {
        return [
            'id' => $i->id,
            'label' => $i->label,
            'project_key' => $i->project_key,
            'status' => $i->status,
            'last_sync_at' => $i->last_sync_at?->toIso8601String(),
            'error' => $i->error_json,
        ];
    }

    /**
     * OAuth-connector account creation / re-grant.
     *
     * find-or-rearm by (tenant, connector, label): an existing account with the
     * same label is re-armed to PENDING (re-grant after a scope expansion or a
     * stuck row); a new label creates a fresh account. project_key is only
     * (re)written when explicitly supplied, so a re-grant never silently clears
     * an existing binding.
     *
     * @return array{installation: ConnectorInstallation, redirect_to: string}
     */
    public function startOAuthInstall(
        string $name,
        string $label,
        ?string $projectKey,
        bool $projectKeyProvided,
        int $createdBy,
    ): array {
        $connector = $this->registry->get($name);
        if ($connector === null) {
            throw new NotFoundHttpException("Connector '{$name}' is not registered.");
        }

        $installation = ConnectorInstallation::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('connector_name', $name)
            ->where('label', $label)
            ->first();

        if ($installation === null) {
            $installation = ConnectorInstallation::create([
                'tenant_id' => $this->tenantContext->current(),
                'connector_name' => $name,
                'label' => $label,
                'project_key' => $projectKey,
                'status' => ConnectorInstallation::STATUS_PENDING,
                'created_by' => $createdBy,
            ]);
        } else {
            $attrs = [
                'status' => ConnectorInstallation::STATUS_PENDING,
                'error_json' => null,
            ];
            if ($projectKeyProvided) {
                $attrs['project_key'] = $projectKey;
            }
            $installation->forceFill($attrs)->save();
        }

        return [
            'installation' => $installation,
            'redirect_to' => $connector->initiateOAuth($installation->id),
        ];
    }

    /**
     * Edit an existing account's metadata (label / project binding). Credential
     * re-auth is intentionally NOT handled here — that re-runs the connector's
     * own configure/OAuth round-trip. Metadata-only edits never touch the vault.
     *
     * @param  array<string,mixed>  $attrs  Subset of {label, project_key}; only
     *                                       present keys are updated (PATCH).
     */
    public function updateMetadata(int $installationId, array $attrs): ConnectorInstallation
    {
        $installation = $this->findOr404($installationId);

        $update = [];
        if (array_key_exists('label', $attrs)) {
            $update['label'] = (string) $attrs['label'];
        }
        if (array_key_exists('project_key', $attrs)) {
            $value = $attrs['project_key'];
            $update['project_key'] = ($value === '' || $value === null) ? null : (string) $value;
        }

        if ($update !== []) {
            $installation->forceFill($update)->save();
        }

        return $installation;
    }

    /**
     * Disconnect upstream (best-effort) and remove the account row. The
     * companion `connector_credentials` row cascades via the FK (R28).
     */
    public function delete(int $installationId): void
    {
        $installation = $this->findOr404($installationId);

        $connector = $this->registry->get($installation->connector_name);
        if ($connector !== null) {
            try {
                $connector->disconnect($installation->id);
            } catch (\Throwable $e) {
                // Best-effort: never block removal of a stuck account just
                // because the upstream revoke endpoint returned non-2xx.
                report($e);
            }
        }

        $installation->delete();
    }

    public function findOr404(int $installationId): ConnectorInstallation
    {
        $installation = ConnectorInstallation::query()
            ->where('id', $installationId)
            ->where('tenant_id', $this->tenantContext->current())
            ->first();

        if ($installation === null) {
            throw new NotFoundHttpException("Installation {$installationId} not found.");
        }

        return $installation;
    }
}
