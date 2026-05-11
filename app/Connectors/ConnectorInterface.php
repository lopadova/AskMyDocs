<?php

declare(strict_types=1);

namespace App\Connectors;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * v4.5/W1 — Contract every external-source connector implements.
 *
 * A connector ships as either:
 *   - A built-in class registered in `config/connectors.php::built_in`
 *     (e.g. `App\Connectors\BuiltIn\GoogleDriveConnector` in W1), OR
 *   - A separate Laravel package declaring its FQCNs under
 *     `composer.json::extra.askmydocs.connectors`. The
 *     {@see ConnectorRegistry} auto-discovers both at boot.
 *
 * The framework guarantees:
 *   - Every method is invoked with the active {@see \App\Support\TenantContext}
 *     already resolved.
 *   - `$installationId` references a row in `connector_installations`
 *     scoped to the active tenant — the controller / job that calls into
 *     the connector enforces R30 isolation before reaching here.
 *   - Credentials are managed by {@see Auth\OAuthCredentialVault}; concrete
 *     connectors NEVER persist tokens themselves.
 *
 * R23: every implementation MUST be validated by the registry at boot
 *      (`instanceof ConnectorInterface`).
 */
interface ConnectorInterface
{
    /**
     * Short, lowercase, kebab-case identifier — e.g. `google-drive`,
     * `notion`, `onedrive`. Used as the URL slug and as
     * `connector_installations.connector_name`. Must be stable across
     * versions (renaming would orphan every existing installation).
     */
    public function key(): string;

    /**
     * Human-facing label rendered in the admin UI — e.g. `Google Drive`.
     */
    public function displayName(): string;

    /**
     * URL of the connector's logo (PNG or SVG, ideally 64×64). The admin
     * SPA `<img>`-loads it directly, so the URL must be CORS-friendly and
     * reachable from the user's browser. Bundled assets ship under
     * `public/connectors/{key}.svg`.
     */
    public function iconUrl(): string;

    /**
     * OAuth scope strings requested from the provider. The framework
     * passes these verbatim to `initiateOAuth()`'s state-token URL
     * builder. Used by the admin UI to surface a "this connector needs
     * permissions: ..." confirmation dialog before kicking off OAuth.
     *
     * @return list<string>
     */
    public function oauthScopes(): array;

    /**
     * Build the provider's OAuth2 authorization URL. The framework
     * pre-creates a `connector_installations` row in status=`pending`
     * and passes its id here so the callback can match. The returned
     * URL is sent back to the admin SPA as a `redirect_to` payload —
     * the browser navigates to it, the user authorizes, and the
     * provider redirects back to the host-app's `oauth/callback`
     * route that ultimately invokes {@see handleOAuthCallback()}.
     *
     * Implementations SHOULD include a state token (`state=...`)
     * round-tripped through the framework's
     * {@see BaseConnector::issueOAuthState()} helper so the callback
     * can verify CSRF.
     */
    public function initiateOAuth(int $installationId): string;

    /**
     * Complete the OAuth flow once the provider redirects back. The
     * concrete implementation exchanges the auth code for tokens
     * (typically a POST to the provider's `/oauth2/token` endpoint),
     * verifies the state token, and persists the tokens via
     * {@see Auth\OAuthCredentialVault::setCredentials()}.
     *
     * On success, the framework flips
     * `connector_installations.status` to `active`. On failure, the
     * implementation MUST throw — the framework leaves the row in
     * `pending` so the admin UI can offer a "retry install" action.
     */
    public function handleOAuthCallback(int $installationId, Request $request): void;

    /**
     * Full sync — discover and ingest every doc the connector knows
     * about. Typically called once at install time + on operator-
     * requested re-sync. Long-running; runs inside
     * {@see \App\Jobs\ConnectorSyncJob}. Implementations should chunk
     * the upstream listing (R3 memory safety) and dispatch one
     * {@see \App\Jobs\IngestDocumentJob} per discovered file.
     */
    public function syncFull(int $installationId): SyncResult;

    /**
     * Incremental sync — fetch only what changed since `$since`. When
     * `$since === null`, the connector falls back to a full sync
     * (typically the case the very first time the scheduler hits a
     * freshly-installed connector). Each provider's "delta" semantics
     * differs (Drive Changes API, Notion last_edited_time, MS Graph
     * delta token, Atlassian CQL last-modified) — the framework
     * doesn't pick a single representation.
     */
    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult;

    /**
     * Disconnect — clear credentials, optionally revoke the access
     * token at the provider, and emit a `connector_disconnected`
     * audit row. The framework deletes the
     * `connector_installations` row AFTER this method returns.
     */
    public function disconnect(int $installationId): void;

    /**
     * Health probe — ping the provider's `about` / `me` endpoint to
     * verify credentials are still valid + the upstream is reachable.
     * MUST be fast (<2s ideally) and side-effect-free.
     */
    public function health(int $installationId): HealthStatus;
}
