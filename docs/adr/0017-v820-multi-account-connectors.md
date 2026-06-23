# ADR 0017 — Multi-account & project-scoped connectors (v8.20)

- **Status:** Accepted
- **Date:** 2026-06-23
- **Cycle:** v8.20 — Ciclo 1 (W1.A–W1.C)
- **Builds on:** ADR 0009 (connector package extraction; chunkers stay in host),
  the connector framework shipped v4.5–v4.6, and the v8.17 credential-connector
  surface (`SupportsCredentialForm`, IMAP).

## Context

Through v8.19 a tenant could connect **exactly one account per connector**,
enforced by `UNIQUE(tenant_id, connector_name)` on `connector_installations`,
and ingested documents landed in a synthetic `connector-<key>` project rather
than a real KB project. Verifying the IMAP connector surfaced that this limits
every connector, not just IMAP: helpdesks have multiple mailboxes, consultancies
have one Drive per client, platform teams want different workspaces routed to
different KB projects.

A pre-flight audit found the connectors were already multi-account-capable where
it matters — sync is keyed on `installationId`, the vault stores one secret per
installation, on-disk paths include `installation-%d`, and the scheduler iterates
all active installations. The only blockers were (1) the unique constraint,
(2) the scattered `project_key ?? 'connector-<key>'` fallback across all eight
connectors, and (3) the host UI/service "one installation per connector"
assumption.

## Decision

1. **Data model lives in `askmydocs-connector-base` (v1.3), not the host.**
   v1.3.0 adds `label` (`string(64)` default `'default'`) + `project_key`
   (`string(120)` nullable), relaxes the unique to
   `UNIQUE(tenant_id, connector_name, label)`, and ships
   `BaseConnector::resolveProjectKey()` (column → config_json legacy →
   `kb.ingest.default_project` → `'default'`). v1.3.1 adds a backfill migration
   that moves legacy `config_json['project_key']` into the column. All eight
   connectors adopted `resolveProjectKey()` and released. The host bumps to
   `^1.3` and mirrors both migrations into the SQLite test bench.

2. **Relax the unique; keep it tenant-first.** `(tenant_id, connector_name, label)`
   preserves the tenant boundary (R30/R31) and makes account identity explicit
   (R28-style). The DB unique is the **authority** for duplicate-label rejection;
   the request-level rule is best-effort UX. The create-race surfaces a friendly
   **422**, never a 500 (R21/R14, driver-tolerant SQLSTATE classification).

3. **`project_key` is a real column, validated against the real registry (R18).**
   The admin dropdown and the `exists` rule both derive from
   `GET /api/admin/projects`. Empty binds to the tenant default; the old
   `connector-<key>` synthetic project is retired.

4. **One core per capability, tri-surface (R44).**
   `ConnectorInstallationService` owns the read summary (shared by HTTP `index`,
   `connectors:list`, and the MCP `ConnectorInstallationsTool`), OAuth-account
   creation (find-or-rearm by label), metadata edits, and deletion.
   `ConfigureConnectorService` owns credential-account creation (secret → vault).
   Concurrency uses `lockForUpdate` inside a transaction on re-arm/edit (R21); a
   synchronous in-flight guard prevents double-trigger races in the FE.

5. **OAuth install = find-or-rearm by label; credential configure = create-only.**
   Re-granting an OAuth scope re-arms the same labelled account (the issued
   `state` token is cached → installation id so the callback resolves the right
   account under concurrent installs); a credential account with an existing
   label is rejected; editing (rename / rebind) is a separate `PATCH`. On a
   `PATCH`, an empty `project_key` clears the binding; on a re-grant, blank leaves
   it untouched (`filled()` not `has()`).

6. **MCP roster 34 → 35** (`ConnectorInstallationsTool`, read-only, R30/R43);
   count locked by `KnowledgeBaseServerRegistrationTest`.

## Consequences

- Tenants connect N labelled accounts per connector, each routed to a real
  project or the tenant default — across PHP / HTTP / MCP.
- No host DB-only state: the markdown + connector packages remain the source of
  truth; `connector_installations` is rebuildable.
- Deleting an account cascades its `connector_credentials` row (R28).
- Re-enabling a disabled account is not yet a first-class action — re-add with
  the same label to re-arm (documented follow-up: an explicit enable endpoint).
- The `connector-base` label-disambiguation helper should adopt the host
  mirror's `mb_substr`/`mb_strlen` fix in a follow-up release (the host mirror is
  already multibyte-safe).

## Surfaces

| Surface | Entry point |
|---|---|
| PHP | `connectors:list` (read) · `connectors:install` (interactive masked secret) · `ConnectorInstallationService` / `ConfigureConnectorService` |
| HTTP | `GET/POST/PATCH/DELETE /api/admin/connectors[/{name}/install|configure][/{installationId}]` (R32 matrix) |
| MCP | `ConnectorInstallationsTool` on `KnowledgeBaseServer::$tools` |
| UI | `frontend/src/features/admin/connectors/` — accounts-per-connector cards + `AccountMetaForm` |
