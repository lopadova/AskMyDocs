# AskMyDocs Connector Framework — Developer Guide

> v4.5/W1 — Connector framework core + Google Drive reference.
> Subsequent W2-W6 add Notion / Evernote / Fabric / OneDrive /
> Confluence / Jira connectors.

This guide is for engineers writing new connectors against the
AskMyDocs connector framework. Read `app/Connectors/ConnectorInterface.php`
alongside this document — the interface contract is the source of truth.

---

## 1. What a connector is

A **connector** bridges an external content source (Google Drive,
Notion, Confluence, ...) to the AskMyDocs ingestion pipeline. The
framework handles:

- Per-tenant **installation** rows (`connector_installations`).
- Encrypted **credential storage** (`connector_credentials`), via
  `App\Connectors\Auth\OAuthCredentialVault`.
- Scheduler-driven **incremental sync** every N minutes, via
  `App\Connectors\Scheduling\SyncScheduler` + `App\Jobs\ConnectorSyncJob`.
- Admin REST surface (`GET/POST/DELETE /api/admin/connectors/...`)
  guarded by the `manageConnectors` Spatie Gate (super-admin only).

Each connector implements `App\Connectors\ConnectorInterface` and is
discovered by the `ConnectorRegistry` from one of two channels:

1. **Built-in** — FQCN listed in `config/connectors.php::built_in`.
   The Google Drive reference connector lands here in v4.5/W1.
2. **Composer package** — declared in the package's `composer.json`
   under `extra.askmydocs.connectors` (array of FQCNs). The pattern
   mirrors Laravel's own `extra.laravel.providers` auto-discovery.

Either channel works; pick whichever fits your distribution model.

---

## 2. The 10-method contract

`App\Connectors\ConnectorInterface`:

| Method | Purpose |
|---|---|
| `key(): string` | Stable kebab-case identifier (e.g. `google-drive`). Used as the URL slug AND `connector_installations.connector_name`. **Never rename.** |
| `displayName(): string` | Human-readable label for the admin UI. |
| `iconUrl(): string` | URL of the connector logo (PNG / SVG, ideally 64×64). |
| `oauthScopes(): array` | OAuth2 scopes requested from the provider. Surfaced in the install confirmation dialog. |
| `initiateOAuth(int $installationId): string` | Build the provider OAuth URL. Return value is sent to the admin SPA as `redirect_to`. |
| `handleOAuthCallback(int $installationId, Request $request): void` | Exchange the auth code for tokens; persist via `OAuthCredentialVault::setCredentials()`. |
| `syncFull(int $installationId): SyncResult` | Discover & ingest every doc the connector knows about. |
| `syncIncremental(int $installationId, ?Carbon $since): SyncResult` | Fetch only what changed since `$since`. When `$since === null`, fall back to `syncFull()`. |
| `disconnect(int $installationId): void` | Revoke upstream token (best-effort), clear local credentials, emit audit. |
| `health(int $installationId): HealthStatus` | Ping the provider's `about` / `me` endpoint. Fast (<2s) + side-effect-free. |

Most connectors extend `App\Connectors\BaseConnector`, which provides
default implementations for:

- CSRF state-token round-trip (`issueOAuthState()` + `consumeOAuthState()`).
- `kb_canonical_audit` emission (`emitAudit()`).
- PII redaction at the ingest boundary (`maybeRedactContent()`).
- Tenant-scoped installation lookup (`loadInstallation()`).

---

## 3. Worked example — minimal connector

```php
<?php

namespace Acme\AskMyDocsConnectorAcme;

use App\Connectors\BaseConnector;
use App\Connectors\HealthStatus;
use App\Connectors\SyncResult;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AcmeConnector extends BaseConnector
{
    public function key(): string { return 'acme'; }
    public function displayName(): string { return 'Acme Docs'; }
    public function oauthScopes(): array { return ['docs:read']; }

    public function initiateOAuth(int $installationId): string
    {
        $state = $this->issueOAuthState($installationId);
        return 'https://acme.com/oauth/authorize?state=' . $state . '&...';
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        if (! $this->consumeOAuthState($installationId, (string) $request->input('state'))) {
            throw new \App\Connectors\Exceptions\ConnectorAuthException('Bad state.');
        }

        $resp = Http::post('https://acme.com/oauth/token', [
            'code' => $request->input('code'),
            // ...
        ])->json();

        $this->vault->setCredentials(
            $installationId,
            accessToken: $resp['access_token'],
            refreshToken: $resp['refresh_token'] ?? null,
            expiresAt: isset($resp['expires_in']) ? Carbon::now()->addSeconds($resp['expires_in']) : null,
        );
    }

    public function syncFull(int $installationId): SyncResult
    {
        $token = $this->refreshTokenIfExpired($installationId);
        // ... walk the API, dispatch IngestDocumentJob per file ...
        return new SyncResult(documentsAdded: 10, documentsUpdated: 0, documentsRemoved: 0, errors: [], completedAt: Carbon::now());
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        if ($since === null) {
            return $this->syncFull($installationId);
        }
        // ... use provider-specific delta query ...
        return SyncResult::empty();
    }

    public function disconnect(int $installationId): void
    {
        $this->vault->clearCredentials($installationId);
        $this->emitAudit('disconnected', installationId: $installationId);
    }

    public function health(int $installationId): HealthStatus
    {
        $token = $this->vault->getAccessToken($installationId);
        if ($token === null) {
            return HealthStatus::errored('No valid token.');
        }
        // ... ping provider /me endpoint ...
        return HealthStatus::healthy();
    }
}
```

### Composer auto-discovery

```jsonc
{
    "name": "acme/askmydocs-connector-acme",
    "extra": {
        "askmydocs": {
            "connectors": [
                "Acme\\AskMyDocsConnectorAcme\\AcmeConnector"
            ]
        }
    }
}
```

When this package is installed, `ConnectorRegistry` auto-discovers
`AcmeConnector` on boot, validates it implements `ConnectorInterface`,
and exposes it at `GET /api/admin/connectors`.

---

## 4. Sync semantics

### Full vs incremental

- `syncFull()` walks the entire upstream surface. Used at install time
  and on operator-triggered re-sync.
- `syncIncremental()` consults a provider-specific cursor (Drive
  Changes API `pageToken`, Notion `last_edited_time`, MS Graph `delta`
  token, Atlassian CQL last-modified). When the cursor is unset (very
  first run), fall back to a full sync.

### Dispatching ingest jobs

Every connector funnels work through `App\Jobs\IngestDocumentJob` —
the single ingestion execution path (per CLAUDE.md §6). Write the
fetched body to the KB disk first, then dispatch the job with the
relative path + MIME type + `tenant_id`. Do NOT re-implement
`DocumentIngestor::ingestMarkdown()` from scratch.

### Memory safety

R3 applies — if your provider has more than a few hundred items,
paginate the upstream and dispatch jobs as you go rather than
collecting everything into a single PHP array. `IngestDocumentJob`
queues each work item independently so paginating + dispatching is
naturally streamy.

---

## 5. PII redaction at the boundary (R26)

When `kb.pii_redactor.enabled=true`, the host operator expects PII
redaction to happen BEFORE the document hits any persistent store —
including the KB disk + embedding cache + chat logs.

`BaseConnector::maybeRedactContent($body)` is the contract: pass
every fetched text/markdown body through it BEFORE writing to disk
+ dispatching the ingest job. The method is a pass-through when
the master switch is OFF (default).

Tests asserting this boundary live in
`tests/Feature/Connectors/GoogleDriveConnectorTest.php::test_pii_redactor_runs_at_ingest_boundary_when_enabled`.

---

## 6. Tenant isolation (R30 / R31)

Every connector inherits the `BaseConnector`'s tenant-scoped
`OAuthCredentialVault` lookup. The vault refuses cross-tenant access
by silently returning `null` when the active `TenantContext` doesn't
match the installation's `tenant_id`. The admin controller
additionally guards every endpoint with a `where('tenant_id', current())`
predicate.

If you bypass `OAuthCredentialVault` and query `connector_credentials`
directly — don't. The whole point of the vault is to make tenant
isolation impossible to forget.

---

## 7. Audit + observability

Every state-changing operation should emit a `kb_canonical_audit` row
via `BaseConnector::emitAudit()`. The framework automatically namespaces
the `event_type` with a `connector_` prefix so the admin log viewer
can filter by connector activity:

- `connector_installed`
- `connector_sync_completed`
- `connector_sync_failed`
- `connector_token_refreshed`
- `connector_disconnected`

`emitAudit()` also records the `installation_id` + `connector` key in
`metadata_json` for forensic queries.

---

## 8. OAuth + security

### State token round-trip

`BaseConnector::issueOAuthState($installationId)` returns a 32-char
random token, stored in the application cache with TTL
`connectors.oauth_state_ttl_seconds` (default 600). The cache entry
carries the active tenant_id + installation_id so a replay attempt
across tenants fails.

`consumeOAuthState($installationId, $token)` is single-use — the
cache entry is removed before the method returns. A second call with
the same token returns false. **This is the CSRF defence for the
OAuth round-trip; do NOT skip it.**

### Token refresh

Refresh logic is per-provider. The recommended pattern:

```php
public function refreshTokenIfExpired(int $installationId): ?string
{
    $access = $this->vault->getAccessToken($installationId);
    if ($access !== null) {
        return $access;
    }

    $refresh = $this->vault->getRefreshToken($installationId);
    if ($refresh === null) {
        return null;
    }

    // POST to provider's /oauth2/token with grant_type=refresh_token ...
    $newAccess = ...;
    $newExpiresAt = ...;

    $this->vault->setCredentials($installationId, $newAccess, $refresh, $newExpiresAt);
    $this->emitAudit('token_refreshed', installationId: $installationId);

    return $newAccess;
}
```

`getAccessToken()` returns `null` when `expires_at < now()` — your
sync code should always go through `refreshTokenIfExpired()` instead
of calling `getAccessToken()` directly.

---

## 9. Testing patterns

### Http::fake'd unit tests

Every connector ships PHPUnit tests with all provider calls stubbed
via `Http::fake()`. See
`tests/Feature/Connectors/GoogleDriveConnectorTest.php` for the
reference template. Cover:

- OAuth URL builder returns the right `scope` + `state` query params.
- Token-exchange success path persists encrypted credentials.
- Token-exchange failure path raises `ConnectorAuthException`.
- `syncFull()` dispatches one `IngestDocumentJob` per fetched doc.
- `syncIncremental()` consumes the delta cursor + persists the new
  cursor for the next run.
- `disconnect()` revokes upstream + clears the credential row.
- `health()` returns the right state on 2xx / 4xx / 5xx / network
  error.
- PII redaction runs at the boundary when
  `kb.pii_redactor.enabled=true`.

### Live tests (opt-in)

Every connector package ships an optional `tests/Live/` suite that
hits the real OAuth + API surface. The Live tests skip themselves
when no provider credentials are present (`env('CONNECTOR_*_CLIENT_ID')`
unset). Run them locally before tagging `v1.0.0`:

```bash
CONNECTOR_GOOGLE_DRIVE_CLIENT_ID=... \
CONNECTOR_GOOGLE_DRIVE_CLIENT_SECRET=... \
phpunit --testsuite Live --filter GoogleDrive
```

CI never runs the Live suite — keeps the gate green even when the
upstream is down.

---

## 10. Admin SPA integration (v4.5/W2+)

The admin SPA surface (`frontend/src/features/admin/connectors/`) is
out of scope for W1 — W2 + W3 wire the UI. The REST endpoints are
already live:

| Route | Verb | Returns |
|---|---|---|
| `/api/admin/connectors` | GET | List of registered connectors + per-tenant installation status |
| `/api/admin/connectors/{name}/install` | GET | `{ installation_id, redirect_to }` — browser navigates to `redirect_to` |
| `/api/admin/connectors/{name}/oauth/callback` | GET | `{ installation_id, status: 'active' }` |
| `/api/admin/connectors/{id}/sync-now` | POST | 202 — queues `ConnectorSyncJob` |
| `/api/admin/connectors/{id}/disable` | POST | `{ status: 'disabled' }` |
| `/api/admin/connectors/{id}` | DELETE | 204 — revokes + clears + deletes |

All endpoints behind `auth:sanctum` + `can:manageConnectors`
(super-admin only by default; the Gate is wired in
`AppServiceProvider::registerConnectorGates()`).

---

## 11. Cross-references

- `app/Connectors/ConnectorInterface.php` — contract source of truth
- `app/Connectors/BaseConnector.php` — default helpers
- `app/Connectors/ConnectorRegistry.php` — auto-discovery + R23 boot
  guard
- `app/Connectors/Auth/OAuthCredentialVault.php` — encrypted store
- `app/Jobs/ConnectorSyncJob.php` — scheduler-dispatched worker
- `app/Connectors/Scheduling/SyncScheduler.php` — cadence walker
- `app/Connectors/BuiltIn/GoogleDriveConnector.php` — reference impl
- `config/connectors.php` — built-in registry + cadence knobs
- `docs/v4-platform/PLAN-v4.5-connector-framework-and-vercel-sdk-completion.md`
  — overall cycle plan
- `tests/Feature/Connectors/` — feature test pack
- `tests/Architecture/ConnectorRegistryTest.php` — R23 architecture
  test
