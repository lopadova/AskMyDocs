<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorEvernote\Support\EnexImporter;
use Padosoft\AskMyDocsConnectorEvernote\Support\InvalidEnexException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v4.5/W4 — Bulk import endpoint for Evernote `.enex` exports.
 *
 * POST /api/admin/connectors/evernote/import-enex
 *
 * Accepts a multipart upload (`enex` field) plus an optional
 * `installation_id` and `project_key`. When `installation_id` is
 * omitted, the controller resolves the active tenant's existing
 * Evernote installation; if there isn't one yet, a STATUS_PENDING row
 * is created on-the-fly so the bulk import can run without forcing
 * the operator through the OAuth dance first.
 *
 * Response shape mirrors {@see \Padosoft\AskMyDocsConnectorEvernote\Support\EnexImporter}:
 *   { "data": { "imported": int, "skipped": int, "errors": list<string> } }
 *
 * R14 — a malformed ENEX surfaces HTTP 422 with a structured payload.
 * Never return 200 on a parse failure.
 */
final class EvernoteEnexController extends Controller
{
    public function __construct(
        private readonly EnexImporter $importer,
        private readonly TenantContext $tenantContext,
    ) {}

    public function importEnex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enex' => ['required', 'file', 'max:512000', 'mimetypes:application/xml,text/xml,application/octet-stream'],
            'installation_id' => ['nullable', 'integer'],
            'project_key' => ['nullable', 'string', 'max:120'],
        ]);

        $installation = $this->resolveInstallation(
            $request,
            isset($validated['installation_id']) ? (int) $validated['installation_id'] : null,
        );

        $installationConfig = (array) ($installation->config_json ?? []);
        $projectKey = $validated['project_key']
            ?? ($installationConfig['project_key'] ?? null)
            ?? 'connector-evernote';

        $uploaded = $request->file('enex');
        if ($uploaded === null) {
            return response()->json([
                'error' => 'enex file is missing.',
            ], 422);
        }

        $localPath = $uploaded->getRealPath();
        if ($localPath === false || $localPath === '') {
            return response()->json([
                'error' => 'enex upload could not be located on the server (temporary file vanished).',
            ], 422);
        }

        try {
            $result = $this->importer->import($localPath, $installation, (string) $projectKey);
        } catch (InvalidEnexException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'invalid_enex',
            ], 422);
        }

        return response()->json([
            'data' => array_merge(
                ['installation_id' => $installation->id, 'project_key' => $projectKey],
                $result->toArray(),
            ),
        ], 202);
    }

    private function resolveInstallation(Request $request, ?int $installationId): ConnectorInstallation
    {
        $tenantId = $this->tenantContext->current();

        if ($installationId !== null) {
            $installation = ConnectorInstallation::query()
                ->where('id', $installationId)
                ->where('tenant_id', $tenantId)
                ->where('connector_name', 'evernote')
                ->first();

            if ($installation === null) {
                throw new NotFoundHttpException(
                    "Evernote installation {$installationId} not found for the active tenant."
                );
            }

            return $installation;
        }

        $installation = ConnectorInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('connector_name', 'evernote')
            ->orderByDesc('id')
            ->first();

        if ($installation !== null) {
            return $installation;
        }

        // No prior installation → create a PENDING row so the import
        // has a place to attach. The operator can still complete the
        // OAuth flow later to enable ongoing API-driven sync.
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'evernote',
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => $request->user()->getAuthIdentifier(),
        ]);
    }
}
