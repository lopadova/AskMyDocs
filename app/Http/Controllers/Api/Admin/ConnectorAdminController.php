<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Jobs\ConnectorSyncJob;
use App\Models\ConnectorInstallation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v4.5/W1 — REST surface for the connector admin SPA.
 *
 * Endpoints:
 *   GET    /api/admin/connectors                          → list
 *   GET    /api/admin/connectors/{name}/install           → start OAuth
 *   GET    /api/admin/connectors/{name}/oauth/callback    → finish OAuth
 *   POST   /api/admin/connectors/{installationId}/sync-now → manual sync
 *   POST   /api/admin/connectors/{installationId}/disable  → pause
 *   DELETE /api/admin/connectors/{installationId}          → disconnect
 *
 * Every action is behind `auth:sanctum` + `can:manageConnectors`
 * (route group in `routes/api.php`). The Gate is wired in
 * `AppServiceProvider::registerConnectorGates()` to super-admin only.
 *
 * R30 — every Eloquent query against `connector_installations`
 *       is scoped by `tenant_id = TenantContext::current()`.
 */
final class ConnectorAdminController extends Controller
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * GET /api/admin/connectors
     *
     * Returns every registered connector (built-in + composer) with
     * its current installation status for the active tenant (null when
     * not installed).
     */
    public function index(): JsonResponse
    {
        $installations = ConnectorInstallation::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->get()
            ->keyBy('connector_name');

        $data = $this->registry->all()->map(function ($connector) use ($installations) {
            $installation = $installations->get($connector->key());

            return [
                'key' => $connector->key(),
                'display_name' => $connector->displayName(),
                'icon_url' => $connector->iconUrl(),
                'oauth_scopes' => $connector->oauthScopes(),
                'installation' => $installation === null ? null : [
                    'id' => $installation->id,
                    'status' => $installation->status,
                    'last_sync_at' => $installation->last_sync_at?->toIso8601String(),
                    'error' => $installation->error_json,
                ],
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/connectors/{name}/install
     *
     * Pre-creates a `connector_installations` row in `pending` status
     * for the active tenant, then asks the connector to build the
     * provider OAuth URL. The browser navigates to `redirect_to` to
     * complete the flow.
     */
    public function startInstall(Request $request, string $name): JsonResponse
    {
        $connector = $this->registry->get($name);
        if ($connector === null) {
            throw new NotFoundHttpException("Connector '{$name}' is not registered.");
        }

        // Re-use a `pending` row if it already exists — operators may
        // re-initiate the install flow without piling up rows.
        $installation = ConnectorInstallation::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('connector_name', $name)
            ->first();

        if ($installation === null) {
            $installation = ConnectorInstallation::create([
                'tenant_id' => $this->tenantContext->current(),
                'connector_name' => $name,
                'status' => ConnectorInstallation::STATUS_PENDING,
                'created_by' => $request->user()->getAuthIdentifier(),
            ]);
        } elseif (in_array($installation->status, [
            ConnectorInstallation::STATUS_ERRORED,
            ConnectorInstallation::STATUS_DISABLED,
        ], true)) {
            // Re-arm the row for a fresh OAuth round-trip.
            $installation->forceFill([
                'status' => ConnectorInstallation::STATUS_PENDING,
                'error_json' => null,
            ])->save();
        }

        $redirectTo = $connector->initiateOAuth($installation->id);

        return response()->json([
            'data' => [
                'installation_id' => $installation->id,
                'redirect_to' => $redirectTo,
            ],
        ]);
    }

    /**
     * GET /api/admin/connectors/{name}/oauth/callback
     *
     * Provider redirect target. Validates the state token, exchanges
     * the auth code for credentials via the connector's
     * `handleOAuthCallback()`, and flips the installation to
     * `active`. The active row is identified by the cached state
     * token; we accept it implicitly via the connector's own
     * validation. To survive concurrent callbacks across tenants we
     * additionally lookup the most-recent `pending` row for the
     * active tenant + connector.
     */
    public function oauthCallback(Request $request, string $name): JsonResponse
    {
        $connector = $this->registry->get($name);
        if ($connector === null) {
            throw new NotFoundHttpException("Connector '{$name}' is not registered.");
        }

        $installation = ConnectorInstallation::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('connector_name', $name)
            ->where('status', ConnectorInstallation::STATUS_PENDING)
            ->orderByDesc('id')
            ->first();

        if ($installation === null) {
            throw new NotFoundHttpException(
                "No pending installation for connector '{$name}' in the active tenant."
            );
        }

        try {
            $connector->handleOAuthCallback($installation->id, $request);
        } catch (ConnectorAuthException $e) {
            $installation->forceFill([
                'status' => ConnectorInstallation::STATUS_ERRORED,
                'error_json' => [
                    'message' => $e->getMessage(),
                    'recorded_at' => now()->toIso8601String(),
                ],
            ])->save();

            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }

        $installation->forceFill([
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'error_json' => null,
        ])->save();

        return response()->json([
            'data' => [
                'installation_id' => $installation->id,
                'status' => $installation->status,
            ],
        ]);
    }

    /**
     * POST /api/admin/connectors/{installationId}/sync-now
     *
     * Dispatches a {@see ConnectorSyncJob} for the named installation.
     * Returns 202 — the actual sync is async.
     */
    public function syncNow(int $installationId): JsonResponse
    {
        $installation = $this->findInstallationOr404($installationId);

        ConnectorSyncJob::dispatch($installation->id, $installation->tenant_id);

        return response()->json([
            'data' => [
                'installation_id' => $installation->id,
                'queued' => true,
            ],
        ], 202);
    }

    /**
     * POST /api/admin/connectors/{installationId}/disable
     *
     * Pauses the scheduler-driven sync without revoking the
     * credentials. Re-enable by re-installing or via a future
     * "enable" action (W3 scope).
     */
    public function disable(int $installationId): JsonResponse
    {
        $installation = $this->findInstallationOr404($installationId);

        $installation->forceFill([
            'status' => ConnectorInstallation::STATUS_DISABLED,
        ])->save();

        return response()->json([
            'data' => [
                'installation_id' => $installation->id,
                'status' => $installation->status,
            ],
        ]);
    }

    /**
     * DELETE /api/admin/connectors/{installationId}
     *
     * Calls the connector's `disconnect()` (which revokes upstream
     * + clears credentials), then deletes the installation row. The
     * companion `connector_credentials` row cascades via FK.
     */
    public function destroy(int $installationId): JsonResponse
    {
        $installation = $this->findInstallationOr404($installationId);

        $connector = $this->registry->get($installation->connector_name);
        if ($connector !== null) {
            try {
                $connector->disconnect($installation->id);
            } catch (\Throwable $e) {
                // Disconnect is best-effort — never block the operator
                // from removing a stuck installation just because the
                // upstream revoke endpoint returned a non-2xx.
                report($e);
            }
        }

        $installation->delete();

        return response()->json(null, 204);
    }

    private function findInstallationOr404(int $installationId): ConnectorInstallation
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
