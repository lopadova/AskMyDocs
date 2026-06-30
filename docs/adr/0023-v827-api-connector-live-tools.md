# ADR 0023 — v8.27: API connector ("Connettore API") — endpoints as live LLM tools

- **Status:** Accepted
- **Date:** 2026-06-30
- **Supersedes/extends:** [ADR 0015](0015-v816-provider-sdk-migration.md) (the
  HYBRID provider verdict)

## Context

Every existing connector (`padosoft/askmydocs-connector-*`) has one job: *ingest*
— download content and index it into the pgvector store. Users repeatedly need
the opposite shape: **fresh, transactional data** (order status, stock, shipment
tracking) that is meaningless to embed because it changes by the minute.

The spec ("Connettore API", Fase 1) asks for a connector where each configured
HTTP **endpoint (Rotta)** becomes a **tool the LLM can call live during a chat
turn**, so retrieval (RAG over the vector store) and real-time API data run in
parallel.

Two structural facts shaped the design:

1. The ingest `ConnectorInterface` (`syncFull`/`syncIncremental`/OAuth/`health`)
   does **not** model "endpoint → tool". Forcing the API connector through it
   would distort both.
2. The chat tool loop (`McpToolCallingService`) was hard-wired to the
   `mcp_servers` table and only ran for OpenAI/OpenRouter — Anthropic + Gemini
   were SDK-only with **no tool path** (ADR 0015).

## Decision

**1 — A standalone package with its own model, not a `ConnectorInterface`
implementation.** `padosoft/askmydocs-connector-api` ships 5 tenant-aware tables
(`api_connectors`, `api_auth_profiles`, `api_routes`, `api_route_parameters`,
`api_tool_call_logs`) and the services that turn a tested endpoint into a tool
(`UrlGuard`, `ApiRouteTester`, `SchemaInferrer`, `ToolDefinitionGenerator`,
`ApiToolExecutor`, `ApiToolRegistry`, auth strategies). It depends on
`connector-base` only for the tenant primitives (`BelongsToTenant`,
`TenantContext`). Fase 2 (ingest from the same routes) is reserved via the
`mode` column but not implemented.

**2 — A second tool source merged into the existing loop, host-side.**
`McpToolCallingService::buildToolIndex()` now merges
`ApiToolRegistry::activeToolsForTenant()` alongside the MCP-server tools (the
index value is polymorphic: `server`-backed MCP tool vs `api_route_id`), and the
dispatch routes API-tool calls to `ApiToolExecutor`. This mirrors how
`HostIngestionBridge` wires the ingest connectors into the host pipeline — the
package supplies the capability, the host wires it. Gated by
`connector-api.chat_tools.enabled` (R43) and decoupled from `mcp.enabled` so the
API source stands alone.

**3 — Reverse the ADR 0015 "no tool path" verdict for Anthropic + Gemini.**
Both gain a raw-`Http::` with-tools branch (Anthropic Messages API
`tool_use`/`tool_result`; Gemini `generateContent` `functionCall`/
`functionResponse`) that translates the OpenAI-shaped tools+history the loop
produces into the native protocol and back. They join OpenAI/OpenRouter in
`TOOL_CAPABLE_PROVIDERS` and become HYBRID (`SDK_HYBRID_TOOL_PROVIDERS`) so the
finops metering bridge meters the raw-Http tool turn exactly once and the
SDK no-tools turn stays SDK-metered (no double count). The no-tools chat +
embeddings paths are unchanged.

**4 — Security is non-negotiable and server-side.** The LLM only ever supplies
`llm`-source parameter values and only ever receives the transformed/byte-capped
response — URLs, headers, `fixed`/`secret` params and auth material never leave
the server, never enter the tool schema, never hit the logs. `UrlGuard` (the
host's first outbound-URL SSRF guard) blocks private/loopback/link-local + cloud
metadata, enforces https and an optional per-tenant domain allowlist, at both
test and run time. Credentials are `encrypted:array` + `$hidden`. Admin routes
carry the authenticated stack (`can:manageConnectors`, R32 matrix row).

## Consequences

- **Positive:** chat answers can cite live data; no schema distortion of the
  ingest contract; one tool loop (no parallel chat path); all four hybrid
  providers usable; tri-surface (PHP/HTTP/MCP) over one core (R44).
- **Negative / watch:** `McpToolCallingService` now knows two tool sources —
  keep the polymorphic index discipline. The Anthropic/Gemini translation layers
  are protocol-specific surface to maintain. SSRF policy is security-load-bearing
  — never weaken `UrlGuard` defaults without review.
- **Do NOT** add an "automatic promotion to canonical" or any path that lets the
  LLM see secrets/URLs; do NOT couple the API tool source back to `mcp.enabled`.
