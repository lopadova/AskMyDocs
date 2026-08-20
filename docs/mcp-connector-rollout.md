# MCP connector rollout

This runbook covers the local path installation and the tenant-by-tenant
replacement of the legacy `mcp_servers` runtime. The deploy switch and the
tenant switch are deliberately separate:

- `MCP_CONNECTOR_ENABLED=false` is the hard, deployment-wide kill switch;
- `connector.mcp.runtime_mode=off|shadow|active` selects the runtime for one
  tenant;
- `off` and `shadow` keep the legacy chat source active; only `active` exposes
  the connector package to chat and disables the legacy source for that tenant.

## Local Composer installation

Keep the three repositories as siblings:

```text
packages/askmydocs-mcp-pack
packages/askmydocs-connector-mcp
www/AskMyDocs
```

AskMyDocs resolves both packages through Composer `path` repositories with
symlinks. No package publication is required:

```bash
cd /path/to/www/AskMyDocs
composer update padosoft/askmydocs-mcp-pack padosoft/askmydocs-connector-mcp -W
php artisan migrate
php artisan optimize:clear
```

Confirm that Composer uses the local paths:

```bash
composer show padosoft/askmydocs-mcp-pack --path
composer show padosoft/askmydocs-connector-mcp --path
```

## Deployment configuration

Start fail-closed:

```dotenv
MCP_CONNECTOR_ENABLED=true
MCP_CONNECTOR_RUNTIME_MODE=off
MCP_CONNECTOR_LEGACY_ADAPTER_ENABLED=true
MCP_CONNECTOR_CLIENT_METADATA_URL=https://app.example.com/.well-known/mcp-client.json
MCP_CONNECTOR_APP_ADVANCED_ENABLED=false
```

For shared internal MCP endpoints, add only explicitly approved hostnames or IP
addresses:

```dotenv
MCP_CONNECTOR_INTERNAL_ENDPOINT_ALLOWLIST=mcp.internal.example
```

Personal endpoints remain public-HTTPS-only. Do not use the allowlist to make a
private endpoint available to personal connections.

MCP Apps need a distinct cookie-free sandbox origin. Leave this unset until the
origin, TLS and CSP routing are deployed:

```dotenv
MCP_CONNECTOR_APP_SANDBOX_ORIGIN=https://mcp-apps.example.com
MCP_CONNECTOR_APP_HOST_ORIGINS=https://app.example.com
```

Keep `MCP_CONNECTOR_APP_ADVANCED_ENABLED=false` until the basic sandbox flow is
validated. Enabling it exposes scoped app-to-chat messages, encrypted model
context, signed downloads and fullscreen requests. Those capabilities remain
unavailable unless the connector runtime is also `active` for the tenant.

Register this OAuth callback at every pre-registered authorization server:

```text
https://app.example.com/api/connectors/mcp/oauth/callback
```

The callback and result pages never put access tokens, refresh tokens or client
secrets in the URL.

## Import and shadow validation

The importer is idempotent. It preserves approved tools, expands a legacy `*`
against the known catalog, encrypts compatible custom headers and retains stdio
definitions as administrator-only `stdio_imported` records:

```bash
php artisan mcp-connectors:import-legacy
php artisan mcp-connectors:import-legacy --tenant=tenant-id
```

As a super-admin, set the tenant-wide App Setting
`connector.mcp.runtime_mode` to `shadow`. It can also be set through
`PUT /api/admin/app-settings` with this body:

```json
{
  "key": "connector.mcp.runtime_mode",
  "project_key": "*",
  "value": "shadow"
}
```

The scheduled comparison runs every six hours. Run it immediately when
validating a tenant:

```bash
php artisan mcp-connectors:shadow --tenant=tenant-id
```

Review `/api/admin/connectors/mcp/shadow-reports`. Do not activate while a
report contains blockers. Warnings must be explained, especially catalog risk
changes, missing tools and version/era differences.

## Connection smoke test

Use the connection public ULID shown by the admin API/UI. Output contains only
catalog counts/hashes and normalized status:

```bash
php artisan mcp-connectors:smoke --connection=01K...
php artisan mcp-connectors:smoke --connection=01K... --json
```

An optional real call is restricted to an enabled, discovered, read-only tool:

```bash
php artisan mcp-connectors:smoke --connection=01K... --tool=documents.search --json
```

Validate at least one server in each applicable row before the pilot:

| Case | Expected result |
|---|---|
| Public modern MCP | era `modern`, protocol `2026-07-28`, stateless discovery |
| Bearer modern MCP | same negotiation, no credential in logs/output |
| Legacy Streamable HTTP | fallback only after method/version rejection; session retained |
| Historical HTTP+SSE | legacy catalog and tool call succeed |
| OAuth | PKCE S256, `resource`, callback state and refresh rotation succeed |
| Empty tool catalog | connection active; discovery status explains the empty catalog |
| Write/unknown tool | disabled until explicit enable; confirmation required |

## Activation

For a pilot tenant:

1. Import twice and confirm the second run creates no duplicate connection.
2. Resolve every shadow blocker.
3. Complete public/Bearer/OAuth smoke tests that apply to the tenant.
4. Confirm shared/project and personal/project visibility in chat.
5. Set `connector.mcp.runtime_mode` to `active`.
6. Monitor tool audit failures, latency, OAuth refreshes, pending interactions
   and remote tasks.

Advance tenants individually. Keep `MCP_CONNECTOR_LEGACY_ADAPTER_ENABLED=true`
during the rollback window; it preserves the old admin routes as adapters but
does not select the chat runtime.

## Rollback

Rollback does not require deleting imported data:

1. Set the affected tenant to `shadow` or `off`; the next chat uses the legacy
   source again.
2. If multiple tenants are affected, set `MCP_CONNECTOR_ENABLED=false` and clear
   the configuration cache.
3. Keep connector tables intact for diagnosis and a later retry.
4. Re-run shadow comparison after fixing the cause, then reactivate the tenant.

Do not drop `mcp_servers`, remove the legacy runtime or disable the legacy API
adapter until the agreed rollback window has expired for every tenant.

## Release gate

Before each rollout build:

```bash
(cd ../../packages/askmydocs-mcp-pack && composer test && composer analyse && composer format:check)
(cd ../../packages/askmydocs-connector-mcp && composer test && composer analyse && composer format -- --test)
vendor/bin/phpunit
npm audit
npm run typecheck
npm run test
npm run build
npx playwright test mcp-connector-super-admin.spec.ts --project=chromium-super-admin
```

Run the package PHPStan/Pint suites in both package repositories and keep CI on
PHP 8.3-8.5 plus Laravel 12-13. Pushes remain an explicit release action after
the local atomic commits and checks are complete.
