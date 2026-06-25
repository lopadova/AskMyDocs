# Hand-off spec — promote IMAP folder discovery into the connector package

**Status:** proposed refactor (the v8.24 folder picker shipped host-side first).
**Owner of the change:** whoever maintains `padosoft/askmydocs-connector-imap`
(+ `padosoft/askmydocs-connector-base`) and the AskMyDocs host.
**Goal:** replace the host-side workaround in
`app/Services/Admin/Connectors/ImapFolderListingService.php` with a first-class,
connector-owned folder-discovery method — removing the host's knowledge of *how
to build an IMAP client* and fixing XOAUTH2 folder discovery for free.

---

## 1. Why

The v8.24 folder picker needs the **live** folder list of an installation. The
connector already knows how to build a client (`ImapConnector::makeClient()`), but
that method is `protected`, so the host currently **reconstructs the client
itself**:

```php
// app/Services/Admin/Connectors/ImapFolderListingService.php (today)
$client = $this->factory->make($connection, $secret, $authMode);   // host rebuilds
$folders = $client->listMailboxes();
```

Two problems with the workaround (acceptable as an interim, not as the end state):

1. **Leaky abstraction** — the host hard-codes the IMAP client-build contract
   (`config_json.connection` shape + `OAuthCredentialVault::getAccessToken` +
   `auth_mode`). Any change to how the connector authenticates breaks the host.
2. **XOAUTH2 is wrong** — the host uses `getAccessToken()` (the *stored* token),
   while the connector's own `makeClient()` calls `refreshTokenIfExpired()` first.
   For an OAuth2 IMAP account whose token has expired, the host workaround fails
   where the connector would succeed.

The refactor moves discovery behind a connector method, so the host calls one
interface and the connector owns auth + client lifecycle.

---

## 2. Shape of the change (two repos + host)

### 2a. `padosoft/askmydocs-connector-base` — new capability interface

Add `src/Contracts/SupportsFolderDiscovery.php`:

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Contracts;

use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;

/**
 * A connector that can enumerate the live containers (folders / labels /
 * spaces / drives) an operator may whitelist for sync. Read-only; tenant
 * scoping is the connector's responsibility (it already resolves the
 * installation through its own tenant-scoped lookup).
 */
interface SupportsFolderDiscovery
{
    /**
     * The live, verbatim container identifiers for the installation — exactly the
     * values that `config_json.folders.include` whitelists (so a picked value
     * round-trips 1:1).
     *
     * @return list<string>
     *
     * @throws ConnectorApiException  when the source is unreachable / unauthorized
     */
    public function listAvailableFolders(int $installationId): array;
}
```

- **Additive, backward-compatible** → a new **minor**: `v1.4.0`.
- Mirrors the existing `SupportsCredentialForm` capability pattern (a connector
  opts in by implementing the interface; the host `instanceof`-checks it — R23
  pluggable-registry: no `if ($name === 'imap')` in the host).

### 2b. `padosoft/askmydocs-connector-imap` — implement it

`ImapConnector implements … , SupportsFolderDiscovery`, reusing the existing
`protected makeClient()`:

```php
public function listAvailableFolders(int $installationId): array
{
    $client = $this->makeClient($installationId);   // handles basic AND xoauth2 (token refresh)
    try {
        return $client->listMailboxes();
    } finally {
        $client->close();
    }
}
```

- `makeClient()` already throws `ConnectorApiException` (via `WebklexImapClient`)
  on connect failure — that satisfies the interface contract; no new error type.
- **Additive** → a new **minor**: `v1.3.0`. Requires `connector-base ^1.4`.

**Optional, same release (closes the date-window gap too):** add
`date_window_days` as a first-class `CredentialField` to
`credentialFormSchema()` so it appears in the *create* form (today it's PATCH-only
on the host). It is a plain scalar — no nested-target work needed:

```php
new CredentialField(
    name: 'date_window_days', label: 'Sync window (days)',
    type: 'number', target: 'config', required: false, default: 365,
    group: 'Sync', help: 'How far back to sync. Default 365.',
),
```

The host's existing `number` validation (used by `port`) covers it; once shipped,
the CLI re-merge of `date_window_days` (`ConnectorImapInstallCommand`) can be
dropped. **`folders.include` deliberately stays OUT of the credential form** — it
needs a live folder list (this interface), so it remains a post-install picker.

### 2c. Versioning + release

| Repo | from → to | reason |
|---|---|---|
| `padosoft/askmydocs-connector-base` | `^1.3` → **`v1.4.0`** | new `SupportsFolderDiscovery` interface |
| `padosoft/askmydocs-connector-imap` | `^1.2` → **`v1.3.0`** | implements it (+ optional date_window_days field); requires base `^1.4` |

Tag + push each repo (the host resolves them via the VCS repositories in
`composer.json`, so a Git tag is enough — Packagist optional). Then in the host:

```bash
# composer.json: bump both requires
#   "padosoft/askmydocs-connector-base": "^1.4",
#   "padosoft/askmydocs-connector-imap": "^1.3",
composer update padosoft/askmydocs-connector-base padosoft/askmydocs-connector-imap
```

Keep the host `r-rules` doc + CLAUDE.md require versions in sync (R9/R6).

---

## 3. Host refactor (AskMyDocs, one PR, after the tags land)

`ImapFolderListingService` delegates to the connector via the registry — drops the
factory + vault dependencies entirely:

```php
final class ImapFolderListingService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ConnectorRegistry $registry,     // was: ImapClientFactoryInterface + OAuthCredentialVault
    ) {}

    /** @return list<string> */
    public function listFolders(int $installationId): array
    {
        $installation = ConnectorInstallation::query()
            ->where('id', $installationId)
            ->where('tenant_id', $this->tenantContext->current())
            ->first();
        if ($installation === null) {
            throw new NotFoundHttpException("Installation {$installationId} not found.");
        }

        $connector = $this->registry->get($installation->connector_name);
        if (! $connector instanceof SupportsFolderDiscovery) {
            throw new NotFoundHttpException(
                "Connector '{$installation->connector_name}' does not support folder discovery.",
            );
        }

        try {
            return array_values(array_map('strval', $connector->listAvailableFolders($installation->id)));
        } catch (\Throwable $e) {
            throw new ImapFolderListingException(
                "Impossibile elencare le cartelle IMAP: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
```

Net deletions in the host:
- Remove `use … ImapClientFactoryInterface;` and `use … OAuthCredentialVault;`
  from `ImapFolderListingService`.
- The endpoint, route, controller method, request, FE picker, DTO, MCP read
  surface all stay **unchanged** — the HTTP/MCP contract does not move.
- Generalisation bonus: the `404 "does not support folder discovery"` branch makes
  the endpoint work for ANY future folder-discovering connector, not just IMAP.

---

## 4. Test checklist

**Package `connector-imap`:**
- [ ] Unit: `listAvailableFolders()` returns the fake factory's mailboxes.
- [ ] Unit: a connect failure surfaces as `ConnectorApiException`.
- [ ] If `date_window_days` field added: `CredentialFormSchemaTest` covers it
      (target `config`, default `365`, not required).

**Host (AskMyDocs):**
- [ ] `tests/Feature/Connectors/ImapFolderListingTest.php` keeps passing — it
      already binds the fake `ImapClientFactoryInterface`, which the connector's
      `makeClient()` consumes, so the happy / empty / 503 paths are unchanged.
- [ ] Add a case: a non-folder-discovering connector id → **404** (the new
      `instanceof` branch), replacing the current `connector_name !== 'imap'`
      guard's coverage.
- [ ] XOAUTH2 regression: an account whose stored token is expired now lists
      folders (the connector refreshes) — the behaviour the host workaround
      couldn't deliver.
- [ ] Full suite green (`php -d memory_limit=2048M vendor/bin/phpunit`).

---

## 5. R-rule compliance

- **R44** (tri-surface): unchanged — PHP (CLI seeds `folders.include`), HTTP
  (`GET /folders` + PATCH), MCP (`ConnectorInstallationsTool`) all keep working
  over the one core; only the *internal* discovery seam moves into the package.
- **R23** (pluggable registry): host `instanceof SupportsFolderDiscovery`, no
  connector-name branch.
- **R9/R6**: bump the require versions + the `CredentialField` `type` PHPDoc / FE
  `type` union together if the optional `date_window_days`/any new field type is
  added.
- **R30/R32**: no route change → no authorization-matrix change; the existing
  `GET /folders` matrix row still covers it.
- **R37/R36/R40/R46**: package PRs target `main` (fresh repos, per R37); the host
  PR runs the standard review + deferred-E2E loop.

---

## 6. Why this was deferred (context for the reviewer)

The picker shipped host-side first (v8.24) because it needed **zero** cross-repo
coordination: the host can reach `ImapClientFactoryInterface` + the vault through
already-public seams. That got the feature live in one repo. This hand-off is the
**debt paydown** — do it when a connector-package release window is open; it is
pure internal cleanup (no user-visible contract change) plus the XOAUTH2 fix.
