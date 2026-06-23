# ADR 0019 — Runtime configuration governance (v8.22)

- **Status:** Accepted
- **Date:** 2026-06-23
- **Cycle:** v8.22 — Ciclo 3 (W1.A–W1.C)
- **Builds on:** the layered-resolver pattern of `KbAnalysisSetting` +
  `ChangeAnalysisGate`, ADR 0015 (provider SDK migration — `ai.provider` is the
  flagship wired knob), and ADR 0018 (`connector.sync_cadence_minutes` is the
  per-project sync cadence).

## Context

Operational knobs (which AI provider a tenant chats with, how often a connector
syncs, whether a master switch is on) were deploy-time only: changing one meant
an env edit + redeploy. Multi-tenant operators need to retune a tenant — or a
single project within a tenant — **at runtime, without a deploy**, while
security-sensitive switches (FinOps enforcement, master kill-switches) must stay
deploy-managed and never be flippable from an admin UI.

There was no governed surface for this: a setting was either an `env()` read or
an ad-hoc table. We needed one curated, validated, tenant-scoped layer that the
PHP/HTTP/MCP surfaces (R44) all share, and that degrades to exactly the
pre-v8.22 (config-default) behaviour when no override exists (R43).

## Decision

1. **`app_settings` + a curated registry.** A tenant-aware table keyed
   `(tenant_id, project_key, setting_key)` → `value_json` (composite UNIQUE,
   `project_key='*'` = tenant-wide). Only keys declared in
   `AppSettingRegistry` are recognised; each descriptor carries `type`
   (enum/int/bool/string), the `config` path supplying the env DEFAULT, a
   `scope` (`tenant` = one value per tenant, `both` = tenant + per-project),
   a `deployOnly` flag, and validation bounds. Secrets are NEVER registered —
   they live only in the encrypted vault.

2. **One layered resolver (`AppSettingsResolver`).** `effective(key, tenant,
   project)` layers `config default ← tenant '*' ← exact-project`, casting to
   the key's type. **Reads honour scope like writes:** a `tenant`-scoped key
   ignores any (stray/legacy) project row, so reads never diverge from what
   `set()` would accept. **Corrupt override rows are skipped, not coerced** —
   an out-of-range int or unknown enum in the DB falls through to the next
   layer rather than silently producing a bad value (R14). The deploy-managed
   config default is the trusted final fallback. A per-request memo serves the
   AI hot path, and is **bypassed in console processes** (queue workers, MCP
   server) so a runtime change is seen on the next job/tool call instead of
   being pinned until restart.

3. **Flagship wiring — AI provider per tenant.** `AiManager::provider()`
   resolves the tenant's `ai.provider` override lazily and **fully guarded**:
   an unknown/unconfigured value, or any governance/DB failure, falls back to
   `config('ai.default')`. The OFF path is byte-for-byte the pre-v8.22
   behaviour (R43), proven by tests in both states.

4. **Deploy-only keys are read-only here.** `ai_finops.enabled` (and future
   master switches) are surfaced for visibility but reject runtime writes with
   a 422 (R14). The set of governable keys is small and explicit by design.

5. **Tri-surface (R44) over the one resolver.** PHP (`app-settings:list` /
   `app-settings:set`), HTTP (`GET`/`PUT /api/admin/app-settings`, gated
   `role:super-admin`, R32 matrix row, R30-scoped), MCP (`AppSettingsTool`
   read surface — one tool added; the roster total is locked by
   `KnowledgeBaseServerRegistrationTest`). A super-admin **Configuration** admin screen
   ships the UI (per-row editor, provenance badge, project-scope selector;
   deploy-only + tenant-scoped-under-project rows are read-only).

## Consequences

- A tenant — or a single project — can be retuned at runtime with a full audit
  of provenance (config / tenant / project) on every surface.
- The chat path is never put at risk by governance: every override read is
  guarded and falls back to the deploy default (R43/R14).
- Security-sensitive knobs remain deploy-managed; the registry is the single
  allow-list, so a new runtime knob is a deliberate registry entry, never an
  accidental exposure.
- The resolver is the one core (R44) — no surface re-implements layering or
  validation; the CLI/HTTP/MCP/UI are thin adapters.

## Surfaces

| Surface | Entry point |
|---|---|
| PHP | `app-settings:list` · `app-settings:set` · `AppSettingsResolver` |
| HTTP | `GET`/`PUT /api/admin/app-settings` (`role:super-admin`, R32) |
| MCP | `AppSettingsTool` on `KnowledgeBaseServer::$tools` (read-only) |
| UI | `frontend/src/features/admin/app-settings/` — Configuration screen |
