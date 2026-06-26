<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Connectors\SerializedConnectorSyncJob;
use App\Http\Requests\Admin\ConfigureConnectorRequest;
use App\Http\Requests\Admin\StartConnectorInstallRequest;
use App\Http\Requests\Admin\UpdateConnectorInstallationRequest;
use App\Http\Resources\Admin\ConnectorInstallationResource;
use App\Services\Admin\Connectors\ConfigureConnectorService;
use App\Services\Admin\Connectors\ConnectorEmailProbeException;
use App\Services\Admin\Connectors\ConnectorEmailProbeService;
use App\Services\Admin\Connectors\ConnectorInstallationService;
use App\Services\Admin\Connectors\ConnectorFolderListingException;
use App\Services\Admin\Connectors\ConnectorFolderListingService;
use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
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
 * `AppServiceProvider::registerConnectorGates()` to admin + super-admin
 * (widened from super-admin only in v8.24 so an admin can run the folder picker).
 *
 * R30 — every Eloquent query against `connector_installations`
 *       is scoped by `tenant_id = TenantContext::current()`.
 */
final class ConnectorAdminController extends Controller
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly TenantContext $tenantContext,
        private readonly ConnectorInstallationService $installations,
    ) {}

    /**
     * GET /api/admin/connectors
     *
     * Returns every registered connector (built-in + composer) with the
     * active tenant's installed ACCOUNTS (v8.20 multi-account — a list;
     * empty when none installed). The shape is the read core
     * {@see ConnectorInstallationService::summary()}, shared verbatim with
     * the MCP read tool and the `connectors:list` command.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->installations->summary()]);
    }

    /**
     * GET /api/admin/connectors/{installationId}/folders
     *
     * v8.25 — live folder/label list for the "connection settings" picker, for ANY
     * connector that implements SupportsFolderDiscovery (IMAP today; the connector
     * owns the upstream client). Returns the (tenant-scoped) installation's
     * container paths so the operator can multi-select which to sync into
     * `config_json.folders.include` / `.exclude`.
     *
     * R30 — cross-tenant / unknown id, or a connector with no folder discovery →
     * 404 (NotFoundHttpException from the service). R14 — an unreachable source /
     * rejected credentials → {@see ConnectorFolderListingException} mapped to 503,
     * never a misleading empty 200. An account with genuinely no folders is a
     * valid 200 with `[]`.
     */
    public function folders(int $installationId, ConnectorFolderListingService $folders): JsonResponse
    {
        try {
            $list = $folders->listFolders($installationId);
        } catch (ConnectorFolderListingException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['data' => ['folders' => $list]]);
    }

    /**
     * GET /api/admin/connectors/{name}/install?label=&project_key=
     *
     * v8.20 multi-account — creates a NEW pending account for the active tenant
     * (or re-arms the existing one with the SAME `label`, e.g. re-granting after
     * a scope expansion), then asks the connector to build the provider OAuth
     * URL. The browser navigates to `redirect_to` to complete the flow. An
     * omitted `label` defaults to 'default' (the single-account flow); pass a
     * distinct label to connect a second account on the same connector.
     *
     * The find-or-rearm-by-label keeps a re-grant on PENDING (the
     * `oauthCallback()` only matches PENDING rows) without disturbing the
     * tenant's OTHER accounts on the same connector.
     */
    public function startInstall(StartConnectorInstallRequest $request, string $name): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->installations->startOAuthInstall(
            $name,
            (string) ($validated['label'] ?? 'default'),
            $validated['project_key'] ?? null,
            // `filled()` (not `has()`): a present-but-blank `?project_key=` must
            // NOT count as "provided" — a re-grant leaves an existing binding
            // untouched (clearing is done via PATCH), per the request docstring.
            $request->filled('project_key'),
            (int) $request->user()->getAuthIdentifier(),
        );

        return response()->json([
            'data' => [
                'installation_id' => $result['installation']->id,
                'redirect_to' => $result['redirect_to'],
            ],
        ]);
    }

    /**
     * GET /api/admin/connectors/{name}/oauth/callback
     *
     * Provider redirect target. Validates the state token, exchanges
     * the auth code for credentials via the connector's
     * `handleOAuthCallback()`, and flips the installation to
     * `active`.
     *
     * v8.20 multi-account: several accounts on the same connector can be PENDING
     * at once, so we resolve the EXACT account the `state` token was issued for
     * (cached at install time) instead of guessing "most recent pending" — which
     * would attach the provider's credentials/error to the wrong account. The
     * most-recent-pending lookup remains a fallback for tokens issued before the
     * mapping existed (and for the single-account case it is identical).
     */
    public function oauthCallback(Request $request, string $name): JsonResponse
    {
        $connector = $this->registry->get($name);
        if ($connector === null) {
            throw new NotFoundHttpException("Connector '{$name}' is not registered.");
        }

        $installation = $this->resolvePendingInstallation($request, $name);

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
        } catch (QueryException $e) {
            // v8.20 — duplicate (tenant, connector, label) lost the create-race
            // (the Request unique rule is best-effort UX; the DB unique is the
            // authority, R21). Surface it as the same field error the rule emits
            // rather than a raw 500.
            if ($this->isDuplicateLabel($e)) {
                throw ValidationException::withMessages([
                    'label' => ['An account with this label already exists for this connector.'],
                ]);
            }

            throw $e;
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
     * Dispatches a {@see SerializedConnectorSyncJob} for the named installation
     * (per-mailbox re-queue, so a manual sync never opens a connection that races
     * another to the same account). Returns 202 — the actual sync is async.
     *
     * Retry semantics (the "Retry sync" button on an ERRORED account):
     * `ConnectorSyncJob::runSync()` skips any installation whose status is not
     * ACTIVE — so a bare dispatch against an ERRORED row is a silent no-op and the
     * operator's "retry" never restarts. This is the operator-driven manual retry,
     * so we re-arm the row to ACTIVE and clear `error_json` BEFORE dispatching; the
     * job's guard then passes and a fresh sync actually runs (and re-stamps ERRORED
     * if it fails again). The scheduler still never auto-resyncs ERRORED rows.
     *
     * R14 — a PENDING (mid-OAuth, no credentials) or DISABLED (operator-paused) row
     * cannot be synced: returning 202 `queued:true` would lie about a job the guard
     * drops. Surface a 422 telling the operator what to do (finish auth / Enable).
     */
    public function syncNow(int $installationId): JsonResponse
    {
        $installation = $this->installations->findOr404($installationId);

        if ($installation->status === ConnectorInstallation::STATUS_PENDING) {
            return response()->json([
                'error' => 'This account is still authorising — finish the connection before syncing.',
            ], 422);
        }

        if ($installation->status === ConnectorInstallation::STATUS_DISABLED) {
            return response()->json([
                'error' => 'This account is disabled — enable it before syncing.',
            ], 422);
        }

        if ($installation->status === ConnectorInstallation::STATUS_ERRORED) {
            // Operator-driven retry: re-arm so the sync job's ACTIVE-only guard
            // passes. A still-failing source flips the row back to ERRORED.
            $installation->forceFill([
                'status' => ConnectorInstallation::STATUS_ACTIVE,
                'error_json' => null,
            ])->save();
        }

        if ($installation->connector_name === 'imap' && config('connectors.imap.serialize_connections', true) === true) {
            SerializedConnectorSyncJob::dispatch($installation->id, $installation->tenant_id);
        } else {
            \Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob::dispatch($installation->id, $installation->tenant_id);
        }

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
     * Pauses the scheduler-driven sync without revoking the credentials. Reversible
     * via {@see enable()} (no re-install / re-grant needed — the vault row is kept).
     */
    public function disable(int $installationId): JsonResponse
    {
        $installation = $this->installations->findOr404($installationId);

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
     * POST /api/admin/connectors/{installationId}/enable
     *
     * Re-activates a previously DISABLED (or ERRORED) account — the inverse of
     * {@see disable()}. Credentials are untouched (disable never revoked them), so
     * the scheduler resumes syncing the account on its next cadence and `error_json`
     * is cleared. Idempotent on an already-ACTIVE row.
     *
     * R14/R43 — a PENDING row is mid-OAuth with no credentials yet; enabling it
     * would let the scheduler sync a credential-less account (→ immediate ERRORED).
     * Reject it with a 422 telling the operator to finish the connection instead.
     */
    public function enable(int $installationId): JsonResponse
    {
        $installation = $this->installations->findOr404($installationId);

        if ($installation->status === ConnectorInstallation::STATUS_PENDING) {
            return response()->json([
                'error' => 'This account is still authorising — finish the connection instead of enabling.',
            ], 422);
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
     * POST /api/admin/connectors/{installationId}/test-fetch
     *
     * Diagnostic — connect to the account and download the SINGLE newest message of
     * a folder, returning a sanitized preview WITHOUT ingesting it (no Storage
     * write, no IngestDocumentJob). Lets the operator confirm credentials + folder
     * access work end-to-end, beyond the bare connection ping. IMAP-only today
     * ({@see ConnectorEmailProbeService}).
     *
     * R14 — a reachable-but-empty folder is a valid 200 with `message: null`; an
     * unreachable mailbox / rejected credentials map to 503; a cross-tenant / unknown
     * id (or a non-IMAP connector) 404s.
     */
    public function testFetch(int $installationId, ConnectorEmailProbeService $probe): JsonResponse
    {
        try {
            $result = $probe->probe($installationId);
        } catch (ConnectorEmailProbeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * PATCH /api/admin/connectors/{installationId}
     *
     * v8.20 — edit an existing account's metadata: rename the `label` and/or
     * rebind `project_key` (blank = inherit the tenant default). PARTIAL —
     * only present keys change. Credential re-auth is out of scope (delete +
     * re-add); this never touches the vault. Returns the updated account.
     */
    public function update(
        UpdateConnectorInstallationRequest $request,
        int $installationId,
    ): JsonResponse {
        try {
            $installation = $this->installations->updateMetadata(
                $installationId,
                $request->validated(),
            );
        } catch (QueryException $e) {
            if ($this->isDuplicateLabel($e)) {
                throw ValidationException::withMessages([
                    'label' => ['An account with this label already exists for this connector.'],
                ]);
            }

            throw $e;
        }

        return response()->json([
            'data' => (new ConnectorInstallationResource($installation))->toArray($request),
        ]);
    }

    /**
     * DELETE /api/admin/connectors/{installationId}
     *
     * Disconnects upstream (best-effort) then deletes the account row. The
     * companion `connector_credentials` row cascades via FK (R28).
     */
    public function destroy(int $installationId): JsonResponse
    {
        $this->installations->delete($installationId);

        return response()->json(null, 204);
    }

    /**
     * Resolve the PENDING installation an OAuth callback belongs to.
     *
     * Prefer the exact account the `state` token was issued for (v8.20
     * multi-account — cached at install time, tenant-scoped). Fall back to the
     * most-recent PENDING row for (tenant, connector) when the token is unknown
     * (legacy installs / single-account), preserving the pre-v8.20 behaviour.
     */
    private function resolvePendingInstallation(Request $request, string $name): ?ConnectorInstallation
    {
        $token = (string) $request->query('state', '');
        if ($token !== '') {
            $installationId = $this->installations->installationIdForState($name, $token);
            if ($installationId !== null) {
                $installation = ConnectorInstallation::query()
                    ->where('id', $installationId)
                    ->where('tenant_id', $this->tenantContext->current())
                    ->where('connector_name', $name)
                    ->where('status', ConnectorInstallation::STATUS_PENDING)
                    ->first();
                if ($installation !== null) {
                    return $installation;
                }
            }
        }

        return ConnectorInstallation::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('connector_name', $name)
            ->where('status', ConnectorInstallation::STATUS_PENDING)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Recognise the (tenant, connector, label) unique violation across drivers.
     *
     * R14: confirm it IS an integrity/unique constraint violation via SQLSTATE
     * BEFORE inspecting the message — so a schema error or unrelated constraint
     * that happens to mention "connector_installations" is never misclassified
     * as a 422. SQLSTATE 23505 = Postgres unique; 23000 = MySQL/SQLite integrity
     * (covers duplicate-key + UNIQUE). Message check then narrows to the specific
     * label constraint: named index (Postgres/MySQL) or the column-list form
     * SQLite emits (`connector_installations.label`).
     */
    private function isDuplicateLabel(QueryException $e): bool
    {
        if (! in_array($e->errorInfo[0] ?? '', ['23000', '23505'], true)) {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'uq_connector_installations_tenant_name_label')
            || str_contains($message, 'connector_installations.label');
    }
}
