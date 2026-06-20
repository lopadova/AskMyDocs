<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\ConfigureConnectorRequest;
use App\Http\Resources\Admin\ConnectorInstallationResource;
use App\Services\Admin\Connectors\ConfigureConnectorService;
use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
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
    public function index(Request $request): JsonResponse
    {
        $installations = ConnectorInstallation::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->get()
            ->keyBy('connector_name');

        $data = $this->registry->all()->map(function ($connector) use ($installations, $request) {
            $installation = $installations->get($connector->key());
            $isCredential = $connector instanceof SupportsCredentialForm;

            return [
                'key' => $connector->key(),
                'display_name' => $connector->displayName(),
                'icon_url' => $connector->iconUrl(),
                'oauth_scopes' => $connector->oauthScopes(),
                // v8.17 — `oauth` (redirect flow, the default) vs `credential`
                // (host-rendered form). The FE branches the Connect button on
                // this flag; the schema is the single source of truth for the
                // form fields (no IMAP-specific FE/BE branch).
                'auth_kind' => $isCredential ? 'credential' : 'oauth',
                'credential_form_schema' => $isCredential ? $connector->credentialFormSchema() : null,
                'installation' => $installation === null
                    ? null
                    : (new ConnectorInstallationResource($installation))->toArray($request),
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
     *
     * iter2 finding #4 — when an installation row already exists for
     * the active tenant + connector (regardless of its current
     * status: ACTIVE / PENDING / ERRORED / DISABLED), we ALWAYS arm
     * it back to PENDING and clear `error_json`. The unique
     * `(tenant_id, connector_name)` constraint allows only one row
     * per tenant per connector, so the reinstall flow MUST drive the
     * single row through the OAuth lifecycle again. If we left the
     * row in ACTIVE while issuing a new OAuth URL, the subsequent
     * `oauthCallback()` 404s (it only looks at PENDING rows) and the
     * operator is stuck — exactly the bug Copilot iter1 caught.
     */
    public function startInstall(Request $request, string $name): JsonResponse
    {
        $connector = $this->registry->get($name);
        if ($connector === null) {
            throw new NotFoundHttpException("Connector '{$name}' is not registered.");
        }

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
        } else {
            // iter2 finding #4 — re-arm regardless of prior status so
            // the reinstall flow goes through the standard
            // PENDING → callback → ACTIVE round-trip even when the
            // operator clicks "Install" on a currently-active row
            // (intentional reinstall, e.g. re-grant after a scope
            // expansion).
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
            // iter2 finding #5 — contract alignment. The
            // ConnectorInterface docblock promises the framework
            // "leaves the row in `pending` so the admin UI can offer
            // a 'retry install' action" on OAuth callback failure
            // (invalid state, code-exchange rejected, ...). We honour
            // that contract here: record the error message in
            // `error_json` for surface visibility, but keep the row
            // STATUS_PENDING so the operator can simply re-click
            // Install and run through the OAuth flow again without
            // the controller treating the row as terminally errored.
            //
            // ERRORED status is reserved for truly non-recoverable
            // failures (token revoked mid-sync, repeated 401 from
            // provider, package uninstalled) — those are stamped by
            // ConnectorSyncJob::recordFailure() and the
            // "connector de-registered" branch of runSync().
            $installation->forceFill([
                'status' => ConnectorInstallation::STATUS_PENDING,
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
     * POST /api/admin/connectors/{name}/configure
     *
     * v8.17 — activate a **credential-based** connector (e.g. IMAP) from the
     * panel. Validates the dynamic schema-driven payload
     * ({@see ConfigureConnectorRequest}) and delegates the whole flow to
     * {@see ConfigureConnectorService}:
     *   - basic  → the service pings + persists, row flips to ACTIVE; on a
     *              credential failure a {@see ConnectorAuthException} surfaces as
     *              **422** with the connector's message (row stays PENDING with
     *              `error_json` — no 200-with-empty-body, R: surface-failures-loudly).
     *   - xoauth2 → row stays PENDING, `redirect_to` carries the provider authorize
     *              URL; the browser finishes via the existing `oauth/callback`.
     *
     * Never logs the payload (it carries the secret). The secret is routed to the
     * encrypted vault by the connector, never into `config_json`/response.
     */
    public function configure(
        ConfigureConnectorRequest $request,
        string $name,
        ConfigureConnectorService $service,
    ): JsonResponse {
        if ($this->registry->get($name) === null) {
            throw new NotFoundHttpException("Connector '{$name}' is not registered.");
        }

        try {
            $result = $service->configure(
                $name,
                $request->validated(),
                (int) $request->user()->getAuthIdentifier(),
            );
        } catch (ConnectorAuthException $e) {
            // Credential verification (e.g. IMAP login ping) failed. The row is
            // left PENDING with error_json by the service; surface a 422 so the
            // form shows the reason instead of a misleading success.
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => array_merge(
                (new ConnectorInstallationResource($result->installation))->toArray($request),
                ['redirect_to' => $result->redirectTo],
            ),
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
