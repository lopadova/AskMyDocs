# ADR 0018 — Ingestion & sync observability + queue baseline (v8.21)

- **Status:** Accepted
- **Date:** 2026-06-23
- **Cycle:** v8.21 — Ciclo 2 (W1.A–W1.C)
- **Builds on:** ADR 0009 (connector packages; `HostIngestionBridge` IoC) and
  ADR 0017 (multi-account installations — sync runs are per `installationId`).

## Context

Through v8.20, connector sync was operationally opaque: all connectors shared a
single sync queue (`CONNECTOR_SYNC_JOB_QUEUE=default`, alongside autowiki +
change-analysis), and there was no per-account record of what a sync run did —
only `connector_installations.status` + `error_json`. Operators couldn't see
queue backlog, per-account sync history (discovered / duration / outcome), or
where ingestion was stuck.

The constraint: `ConnectorSyncJob` is **package-owned** (`askmydocs-connector-base`)
and emits no domain events, and `SyncResult` is internal to its `handle()`. So
the host cannot read sync internals directly without a package change.

## Decision

1. **Queue baseline (host config only).** `CONNECTOR_SYNC_JOB_QUEUE` now defaults
   to `connectors` (was `default`); `KB_INGEST_QUEUE` stays `kb-ingest`. Run a
   worker per queue (`queue:work --queue=connectors|kb-ingest|<default>`). The
   "default" queue NAME is resolved from the active connection
   (`REDIS_QUEUE`/`DB_QUEUE`/…), never assumed to be the literal `default`.
   Per-connector / per-project routing is explicitly deferred (add only if a
   connector becomes a noisy neighbour).

2. **`connector_sync_runs` recorded HOST-SIDE via the queue lifecycle — no
   package change.** `ConnectorSyncRunRecorder` listens on Laravel's
   `JobProcessing` / `JobProcessed` / `JobFailed` events, matches
   `ConnectorSyncJob` (by `commandName ?? displayName`, `allowed_classes`-guarded
   unserialize), and opens/closes a tenant-scoped run row (started/finished,
   duration, status running/success/partial/failed, error). A singleton
   `SyncRunContext` counts `items_discovered` as `HostIngestionBridge::dispatchIngestion`
   fires during the run. Recording is **best-effort** — every write is
   try/catch-guarded so it never breaks the sync path, and the run context is
   always released.

3. **Tri-surface (R44) read over one `IngestionObservabilityService`:** PHP
   (`ingestion:status`), HTTP (`GET /api/admin/ingestion/queue` +
   `GET /api/admin/connectors/{installationId}/sync-runs`, R32 matrix row,
   R30-scoped, cross-tenant 404), MCP (`KbIngestionStatusTool`, roster 35→36).
   A "Ingestion & Sync" admin screen (queue cards + per-account history) ships
   the UI.

4. **Per-document status (from `flow_runs`) is a deliberate follow-up.** The
   Flow engine's `flow_runs` is not tenant-aware, so exposing it safely requires
   a tenant-scoping pass first — tracked for a later v8.21.x / v8.22 PR rather
   than shipped half-isolated.

## Consequences

- Connector sync is isolated from the chat / `default` hot path and observable
  per account, across PHP / HTTP / MCP / UI.
- No package release was needed — the observability is a pure host concern,
  decoupled from the connector packages (they stay standalone-agnostic).
- `connector_sync_runs` is a forensic history with no FK to
  `connector_installations` (survives an installation delete, like
  `kb_canonical_audit`).
- The recorder is resilient to queue-driver payload differences (commandName vs
  displayName) and to deleted/missing installations (records with
  `unknown`/`default` metadata rather than skipping).

## Surfaces

| Surface | Entry point |
|---|---|
| PHP | `ingestion:status` · `IngestionObservabilityService` |
| HTTP | `GET /api/admin/ingestion/queue` · `GET /api/admin/connectors/{installationId}/sync-runs` (R32) |
| MCP | `KbIngestionStatusTool` on `KnowledgeBaseServer::$tools` |
| UI | `frontend/src/features/admin/ingestion/` — queue cards + sync-run table |
