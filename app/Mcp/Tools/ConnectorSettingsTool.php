<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Admin\Connectors\ConnectorInstallationService;
use App\Services\Admin\Connectors\ConnectorSettingsService;
use App\Services\Admin\Connectors\ConnectorFolderListingException;
use App\Services\Admin\Connectors\ConnectorFolderListingService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.25 — MCP read surface (R44) for a connector account's editable sync
 * settings: the connector-advertised schema
 * ({@see ConnectorSettingsService::schemaFor}) plus the current value of each
 * field, and — for a folder-discovering connector — its live folder list.
 *
 * The third surface over the SAME core as the HTTP connector index/resource
 * (which embeds `connection_settings_schema` + `settings`) and the
 * `connectors:configure` Artisan command. Strictly tenant-scoped (R30): a
 * cross-tenant / unknown id returns a clean "not found", never another tenant's
 * config. Degrades cleanly (R43): a connector with no settings returns an empty
 * schema; live folder discovery is opt-in and a failure is reported as a distinct
 * `folders_error`, never a misleading empty list (R14). config_json connection /
 * secret values are NEVER exposed — only schema-declared settings.
 */
#[Description('Read a connector account\'s editable sync settings: the schema of configurable fields plus each field\'s current value, and (opt-in) the live folder list for folder-discovering connectors (e.g. IMAP). Read-only, tenant-scoped.')]
#[IsReadOnly]
#[IsIdempotent]
class ConnectorSettingsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'installation_id' => $schema->integer()
                ->description('The connector_installations.id of the account to inspect (tenant-scoped).')
                ->required(),
            'include_folders' => $schema->boolean()
                ->description('When true, also return the live folder/label list for a folder-discovering connector. Hits the source, so it may be slow or fail. Default false.')
                ->default(false),
        ];
    }

    public function handle(
        Request $request,
        ConnectorInstallationService $installations,
        ConnectorSettingsService $settings,
        ConnectorFolderListingService $folderListing,
        TenantContext $tenants,
    ): Response {
        $installationId = (int) $request->get('installation_id');

        try {
            $installation = $installations->findOr404($installationId);
        } catch (NotFoundHttpException) {
            return Response::json([
                'error' => "Installation {$installationId} not found for this tenant.",
            ]);
        }

        $payload = [
            'tenant_id' => $tenants->current(),
            'installation' => [
                'id' => $installation->id,
                'connector_name' => $installation->connector_name,
                'label' => $installation->label,
                'project_key' => $installation->project_key,
                'status' => $installation->status,
            ],
            // Resolve the schema once and reuse it for the current-values pass.
            'connection_settings_schema' => $schema = $settings->schemaFor($installation),
            'settings' => $settings->currentSettings($installation, $schema),
            'folders' => null,
            'folders_error' => null,
        ];

        if ((bool) ($request->get('include_folders') ?? false)) {
            try {
                $payload['folders'] = $folderListing->listFolders($installation->id);
            } catch (NotFoundHttpException) {
                // The connector has no folder discovery — not an error, just absent.
                $payload['folders'] = null;
            } catch (ConnectorFolderListingException $e) {
                // R14 — the source was unreachable; report it distinctly.
                $payload['folders_error'] = $e->getMessage();
            }
        }

        return Response::json($payload);
    }
}
