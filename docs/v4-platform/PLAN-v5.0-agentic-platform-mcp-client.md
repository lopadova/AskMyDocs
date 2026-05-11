# PLAN — v5.0 Agentic Platform + MCP Client

**Cycle:** v5.0 (post v4.5 GA — semantic major bump)
**Duration:** ~8 weeks (W1..W8)
**Integration branch:** `feature/v5.0` (R37)
**RC tags expected:** `v5.0.0-rc1` (after W2), `v5.0.0-rc2` (after W5), `v5.0.0-rc3` (after W7), GA `v5.0.0` at W8 closure
**Status:** PLAN — pending v4.5 GA

---

## 1. Cycle goal

> **Pivot AskMyDocs from RAG platform to AI Hub — host invokes MCP tools at chat time, opens to infinite external sources (CRM / ERP / DB / SaaS) without ingestion duplication.**

This is the paradigm shift Lorenzo flagged on 2026-05-11 (`memory:feedback_v45_strategic_roadmap`):

- **v4.x** = "RAG + chat on ingested docs" (markdown → ingestion → vector store → retrieval at chat time)
- **v5.0** = "AI Hub agentic — RAG local + chat-time tool-call on business systems via MCP"

Why semantic major bump:
- Connector framework (v4.5) duplicates external content into AskMyDocs storage. Pro: fast chat-time, citations, canonical pipeline, PII at persist boundary. Con: data duplicated, sync periodic.
- MCP outward (v5.0) invokes tool at runtime → reads/writes directly on external source. Pro: data real-time, no duplication, works on dynamic/transactional sources (CRM/ERP/SQL DB). Con: round-trip per chat, no canonical pipeline.

Connector + MCP outward are **complementary, not alternatives**:
- Connector = documentary "slow" sources (docs, KB, notes, wiki)
- MCP = dynamic/transactional sources (orders, tickets, inventory, CRM)
- Hybrid = Confluence both (connector for static pages, MCP for live metadata)

Lorenzo's strategic insight (confirmed): MCP outward is **more strategic long-term** because (1) opens infinite sources via any MCP-compatible server, (2) zero ingestion storage cost, (3) works on sources without documentary APIs (raw SQL DBs, ERPs, payment systems).

---

## 2. Scope — four tracks

### Track A — Node sidecar MCP client orchestrator

Lorenzo decided 2026-05-11: **Node sidecar via `@modelcontextprotocol/sdk` v1.x**, NOT Laravel custom MCP client. Trade-off accepted: 2 runtimes (PHP + Node) vs Laravel custom (would cover only ~40% of MCP server ecosystem because most reference MCP servers are stdio-transport-only, and stdio in PHP is non-trivial).

Why Node sidecar wins:
- Official SDK from Anthropic — breaking spec changes covered automatically by upstream
- All 3 transports supported (stdio + SSE + HTTP streamable); ~60% of MCP server population is stdio-transport-only
- 50+ reference MCP servers are TypeScript/Node — testing against real servers easier on same runtime
- Pattern precedent: n8n, Trigger.dev — "PostgreSQL + Redis + Node sidecar" deploy well tolerated in enterprise

**Architecture:**

```
┌─────────────────────────────────────────────────────────┐
│  Laravel (PHP 8.3+)                                     │
│  ┌────────────────────────────────────────────────────┐ │
│  │  KbChatController::streamReply()                   │ │
│  │  │                                                 │ │
│  │  ├─► AiManager::chat({ tools: [...] })             │ │
│  │  │   (provider receives tool schemas from MCP)     │ │
│  │  │                                                 │ │
│  │  ├─► Provider returns tool_use response           │ │
│  │  │                                                 │ │
│  │  ├─► ToolInvoker::invoke(toolName, input)          │ │
│  │  │   │                                             │ │
│  │  │   └─► HTTP POST localhost:3535/invoke-tool      │ │
│  │  │                       │                         │ │
│  │  └──────────────────────┼─────────────────────────┘ │
│  └─────────────────────────┼───────────────────────────┘
                              │ HTTP (localhost only)
                              ▼
┌─────────────────────────────────────────────────────────┐
│  Node sidecar (Node 22 LTS + TypeScript)                │
│  ┌────────────────────────────────────────────────────┐ │
│  │  HTTP server :3535                                 │ │
│  │  ├─► POST /invoke-tool                            │ │
│  │  │   ↓                                             │ │
│  │  ├─► ToolRegistry::resolve(toolName)              │ │
│  │  ├─► CredentialResolver::fetch(serverId, tenant)  │ │
│  │  │   (callback to Laravel /api/mcp/credentials)   │ │
│  │  ├─► StdioMcpClient | SseMcpClient |              │ │
│  │  │   StreamableHttpMcpClient (per transport)      │ │
│  │  ├─► MCP server invocation                        │ │
│  │  └─► Return result JSON to Laravel                │ │
│  └────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘
```

**Repo layout:**

```
mcp-client/                       # new top-level directory in AskMyDocs repo
├── package.json                  # @modelcontextprotocol/sdk + TypeScript + tsx
├── tsconfig.json
├── Dockerfile                    # multi-stage build (deps + lean runtime)
├── supervisord.conf              # process supervision
├── src/
│   ├── server.ts                 # HTTP server on localhost:3535
│   ├── clients/
│   │   ├── McpClientBase.ts      # abstract client base
│   │   ├── StdioMcpClient.ts     # stdio transport (child_process.spawn)
│   │   ├── SseMcpClient.ts       # SSE transport
│   │   └── StreamableHttpMcpClient.ts  # HTTP streamable transport
│   ├── registry/
│   │   ├── ToolRegistry.ts       # per-workspace tool definitions cache
│   │   └── CredentialResolver.ts # HTTP callback to Laravel for encrypted creds
│   ├── logging/
│   │   └── LaravelStdoutLogger.ts # emits JSON lines consumed by Laravel Monolog
│   ├── auth/
│   │   └── LaravelTokenVerifier.ts # verifies internal Sanctum token on each request
│   └── types/
│       └── mcp.ts                # shared TS types mirroring Laravel side
├── tests/
│   ├── unit/
│   ├── integration/
│   └── fixtures/                  # fake MCP server fixtures (Node tape pattern)
└── README.md
```

**Process supervision:**
- `supervisord` config in `mcp-client/supervisord.conf` ensures auto-restart on crash
- Healthcheck endpoint `GET /healthz` consumed by Laravel
- Logs piped to Laravel via stdout JSON + Monolog stdin handler (single observability surface)

**Authentication boundary:**
- Node sidecar listens on `localhost:3535` only (no external bind)
- Every HTTP request from Laravel carries internal Sanctum token (rotated per deploy)
- Node sidecar verifies token via `LaravelTokenVerifier` (callback to Laravel `/api/mcp/internal-auth`)

### Track B — Laravel MCP host integration

**Migrations:**

```php
// database/migrations/2026_XX_XX_create_mcp_servers_table.php
Schema::create('mcp_servers', function (Blueprint $table) {
    $table->id();
    $table->string('tenant_id', 50)->default('default')->index();
    $table->string('name', 100);                              // human label
    $table->enum('transport', ['stdio', 'sse', 'http']);
    $table->string('endpoint', 500);                          // command/URL
    $table->text('auth_config_encrypted')->nullable();        // Crypt::encryptString
    $table->json('enabled_tools_json')->nullable();           // ['search', 'create_issue']
    $table->enum('status', ['pending', 'active', 'disabled', 'errored'])->default('pending');
    $table->timestamp('last_handshake_at')->nullable();
    $table->json('handshake_response_json')->nullable();      // tools/resources discovered
    $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
    $table->timestamps();
    $table->unique(['tenant_id', 'name'], 'uq_mcp_servers_tenant_name');
    $table->index(['tenant_id', 'status']);
});

// database/migrations/2026_XX_XX_create_mcp_tool_call_audit_table.php
Schema::create('mcp_tool_call_audit', function (Blueprint $table) {
    $table->id();
    $table->string('tenant_id', 50)->default('default')->index();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('mcp_server_id')->constrained('mcp_servers')->cascadeOnDelete();
    $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
    $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
    $table->string('tool_name', 100);
    $table->json('input_json_redacted');                      // pii-redactor passed first
    $table->string('result_hash', 64);                        // SHA-256 of result body
    $table->integer('duration_ms');
    $table->enum('status', ['ok', 'error', 'timeout', 'denied']);
    $table->json('error_json')->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->index(['tenant_id', 'created_at']);
    $table->index(['tenant_id', 'mcp_server_id', 'tool_name']);
});
```

**PHP-side components:**

| Component | Path | Role |
|---|---|---|
| `McpClientBridge` | `app/Mcp/Client/McpClientBridge.php` | HTTP client to Node sidecar. Wraps `Http::` with timeout, retry, healthcheck-precheck. |
| `ToolInvoker` | `app/Mcp/Client/ToolInvoker.php` | High-level: receives `(serverId, toolName, input)`, redacts input via pii-redactor, calls bridge, persists audit row, returns result. |
| `McpServerRegistry` | `app/Mcp/Client/Registry/McpServerRegistry.php` | Eloquent + cache. Returns enabled tools for active tenant. Drives `tools[]` payload to AI provider. |
| `McpToolAuthorizer` | `app/Mcp/Client/McpToolAuthorizer.php` | Per-user / per-tool authorisation. Combines Spatie permission + per-tenant config + per-conversation override. |
| `McpHandshakeService` | `app/Mcp/Client/McpHandshakeService.php` | Initial handshake when a new MCP server is registered — discovers tools/resources, populates `handshake_response_json`. |

**HTTP entrypoints:**

| Route | Controller method | Spatie Gate |
|---|---|---|
| `GET /api/admin/mcp-servers` | `McpServersAdminController::index` | `manageMcpTools` |
| `POST /api/admin/mcp-servers` | `McpServersAdminController::store` | `manageMcpTools` |
| `POST /api/admin/mcp-servers/{id}/handshake` | `McpServersAdminController::handshake` | `manageMcpTools` |
| `PATCH /api/admin/mcp-servers/{id}/tools` | `McpServersAdminController::updateEnabledTools` | `manageMcpTools` |
| `POST /api/admin/mcp-servers/{id}/disable` | `McpServersAdminController::disable` | `manageMcpTools` |
| `DELETE /api/admin/mcp-servers/{id}` | `McpServersAdminController::destroy` | `manageMcpTools` |
| `GET /api/admin/mcp-tool-call-audit` | `McpToolCallAuditController::index` | `viewMcpAudit` |
| `POST /api/mcp/internal-auth` (Node sidecar callback) | `McpInternalAuthController::verify` | internal Sanctum token only |
| `POST /api/mcp/credentials` (Node sidecar callback) | `McpInternalAuthController::credentials` | internal Sanctum token only |

**Admin SPA:** `frontend/src/features/admin/mcp-tools/`
- `McpToolsView.tsx` — list registered MCP servers + per-server enabled-tools matrix
- `RegisterServerDialog.tsx` — form to register new (transport / endpoint / auth)
- `HandshakeStatus.tsx` — handshake result display + retry CTA
- `ToolMatrix.tsx` — per-tool enable/disable per-server + per-tool RBAC config
- `McpToolCallAuditView.tsx` — audit log browser with filters (server / tool / user / status / date)

**Gates:**
- `manageMcpTools` — super-admin only (Spatie role + permission)
- `invokeMcpTools` — per-user, default off; granted by super-admin per-tenant
- `viewMcpAudit` — admin + super-admin

### Track C — Vercel AI SDK tools wiring

Extends `KbChatController::streamReply()` to emit `tools: {...}` in the model request, dynamically loaded from `McpServerRegistry` for the active tenant.

**Wire changes:**

```php
// app/Http/Controllers/KbChatController.php (excerpt — concept)
$activeTools = app(McpServerRegistry::class)->enabledToolsForTenant(
    $request->user()->currentTenantId(),
    $request->user(),
);

$response = $aiManager->chatStream($model, $messages, [
    'tools' => $activeTools,  // NEW — populated from McpServerRegistry
    'onToolCall' => fn ($call) => $this->handleToolCall($call, $request->user()),
]);
```

**Tool call handler** (`KbChatController::handleToolCall`):
1. Authorise via `McpToolAuthorizer::canUserInvoke($user, $serverId, $toolName, $conversation)`
2. If denied → emit `tool-result` UIMessage chunk with `denied: true` + reason
3. If allowed → `ToolInvoker::invoke($serverId, $toolName, $callInput)`
4. Emit tool-result UIMessage chunk with payload
5. Audit row written by `ToolInvoker` regardless of allow/deny

**FE chat extensions:**
- `frontend/src/features/chat/tool-call-renderer/` — new directory
- `ToolCallBubble.tsx` — renders `parts[].type === 'tool-<toolName>'` parts with state machine UI (input-streaming → input-available → output-available / output-error)
- `ToolResultPreview.tsx` — renders typed tool results (JSON tree by default; per-tool custom renderers registered via dispatcher)
- `ToolApprovalDialog.tsx` — when MCP server marks tool as "requires approval", FE blocks the stream with a dialog before invoke proceeds (uses `addToolApprovalResponse` SDK helper)

### Track D — Plugin pattern for MCP tools (mirrors connector framework)

Same plugin pattern as v4.5 connector framework: `padosoft/askmydocs-mcp-tool-{name}` packages.

**5 reference implementations:**

| Wn | Package | License | MCP transport | Use case |
|---|---|---|---|---|
| W3 | `padosoft/askmydocs-mcp-tool-github` | MIT (OS) | stdio | Wraps official `mcp-server-github`. Issue/PR/repo queries. Eat-your-own-dog-food: AskMyDocs's own repo queries. |
| W4 | `padosoft/askmydocs-mcp-tool-slack` | MIT (OS) | stdio | Wraps official `mcp-server-slack`. Workspace search + DM + channel messages. |
| W5 | `padosoft/askmydocs-mcp-tool-postgresql` | MIT (OS) | stdio | Generic SQL DB query tool (read-only by default, write requires explicit auth flag). |
| W6 | `padosoft/askmydocs-mcp-tool-ecommerce` | **Proprietary (Pro)** | stdio | Padosoft custom e-commerce business logic — order lookups, inventory checks, customer service prompts. |
| W7 | `padosoft/askmydocs-mcp-tool-patent-box` | **Proprietary (Pro)** | stdio | Padosoft Patent Box dossier helper — tax dossier auto-fill, R&D classification, Italian compliance. |

Each package ships:
- `composer.json` — declares `extra.askmydocs.mcp-tools` array
- `src/{Name}McpToolServiceProvider.php` — registers the tool launch config (stdio command line) in `McpServerRegistry` at boot
- `mcp-server/` — bundled MCP server (Node) installed via npm post-install hook OR shipped pre-bundled
- `README.md` — WOW pattern per memory feedback (14 sections + 🚀 AI vibe-coding pack section + Features-at-a-glance + Live testsuite opt-in section)

---

## 3. W1..W8 breakdown

### W1 — Node sidecar scaffold + Laravel migration + handshake test

**Sub-PRs:**
- `W1.A` — `mcp-client/` Node sidecar scaffold (package.json + tsconfig + server.ts + StdioMcpClient + minimal HTTP server + healthcheck)
- `W1.B` — Migrations (`mcp_servers` + `mcp_tool_call_audit`) + Eloquent models + factories
- `W1.C` — `McpClientBridge` (PHP-side HTTP client to Node sidecar) + `McpHandshakeService` + Sanctum internal-auth flow
- `W1.D` — Integration test: register fake stdio MCP server fixture in Node + handshake from Laravel + assert `handshake_response_json` populated
- `W1.E` — Dockerfile multi-stage + supervisord config + healthcheck wired in `docker-compose.dev.yml`
- `W1.F` — Wn closure status doc

**Tests expected:** ~30 new (PHP + Node + cross-runtime integration).

**Risk:** **HIGH** — first cross-runtime Wn; multi-runtime ops complexity surfaces here. Mitigation: keep Node sidecar surface MINIMAL in W1 (only stdio + handshake; SSE/HTTP transports + tool-invoke land W2).

### W2 — Laravel HTTP bridge + admin CRUD for MCP servers + credential vault

**Sub-PRs:**
- `W2.A` — `McpServersAdminController` + routes + `RegisterMcpServerRequest` validation
- `W2.B` — Credential vault: `auth_config_encrypted` write path via `Crypt::encryptString` + Node sidecar callback to `/api/mcp/credentials` to retrieve at invoke time
- `W2.C` — Admin SPA scaffold — `McpToolsView.tsx` + `RegisterServerDialog.tsx` + `HandshakeStatus.tsx`
- `W2.D` — Node sidecar SSE + HTTP streamable transports (remaining 2 of 3)
- `W2.E` — `rc1` tag — `v5.0.0-rc1` at W2 closure (R39)
- `W2.F` — Wn closure status doc

**Tests expected:** ~35 new.

**Risk:** **medium** — credential vault is RCE-class if mis-encrypted. R21 (security invariants atomic) applies.

### W3 — AskMyDocs's own KnowledgeBase MCP server registered as eat-your-own-dog-food + GitHub MCP integration

**Sub-PRs:**
- `W3.A` — Register AskMyDocs's own `KnowledgeBaseServer` (existing `app/Mcp/Servers/KnowledgeBaseServer.php` from v3) as a tool consumable BY ITSELF via the new client framework. Closes the loop: AskMyDocs chat invokes its own KB-search tool. This is the "eat-your-own-dog-food" milestone.
- `W3.B` — `padosoft/askmydocs-mcp-tool-github` package (new public repo)
- `W3.C` — Tool result UIMessage parts wiring — `frontend/src/features/chat/tool-call-renderer/` directory + `ToolCallBubble.tsx` + `ToolResultPreview.tsx`
- `W3.D` — Chat extension: BE handler in `KbChatController::streamReply()` emits tool-call UIMessage chunks; FE renders them
- `W3.E` — Playwright E2E: register GitHub MCP, ask "what's the latest PR on lopadova/AskMyDocs", verify tool-call bubble renders + result preview shows
- `W3.F` — Wn closure status doc

**Tests expected:** ~40 new.

**Risk:** **medium** — tool-call UIMessage rendering is a new chat surface. Tier-2 work from v4.5/W7 (generic `data-*` registry, multimodal) is prerequisite — confirm it landed cleanly in v4.5 GA before starting W3.

### W4 — Slack MCP + tool authorization (per-user Gates)

**Sub-PRs:**
- `W4.A` — `padosoft/askmydocs-mcp-tool-slack` package (new public repo)
- `W4.B` — `McpToolAuthorizer` — per-user / per-tool / per-conversation authorisation matrix
- `W4.C` — Admin SPA: `ToolMatrix.tsx` — per-tool enable/disable per-server + per-tool RBAC config
- `W4.D` — Spatie permission seed: `invokeMcpTools` (per-user, default off; super-admin grants)
- `W4.E` — Tool approval dialog wiring — when MCP server marks tool as "requires approval", FE blocks the stream with `ToolApprovalDialog`
- `W4.F` — Wn closure status doc

**Tests expected:** ~35 new.

**Risk:** **medium** — per-tool authorisation matrix expands the Spatie permission surface meaningfully. Cross-tenant isolation R30 test must extend to MCP servers + audit table.

### W5 — PostgreSQL MCP + Vercel SDK tools wiring + UI for tool-call rendering

**Sub-PRs:**
- `W5.A` — `padosoft/askmydocs-mcp-tool-postgresql` package — generic SQL DB query tool (read-only default + write requires explicit auth flag)
- `W5.B` — `AiProviderInterface::chat()` extension to accept `tools` parameter + per-provider tool schema translation (OpenAI / Anthropic / Gemini / OpenRouter / Regolo)
- `W5.C` — Tool call result rendering: complete tool-call bubble UI with state machine (input-streaming / input-available / output-available / output-error)
- `W5.D` — `sendAutomaticallyWhen` wiring — `lastAssistantMessageIsCompleteWithToolCalls` predicate fires automatic follow-up turn after tool results land
- `W5.E` — `rc2` tag — `v5.0.0-rc2` at W5 closure (R39)
- `W5.F` — Wn closure status doc

**Tests expected:** ~50 new (provider tool-schema translation × 5 providers + UI state machine + sendAutomaticallyWhen flow).

**Risk:** **HIGH** — provider tool-schema translation is non-trivial; each provider has subtly different schemas. Mitigation: phase per-provider; OpenAI + Anthropic land in W5, others can slip to W6 if needed.

### W6 — E-commerce Pro MCP tool + chat-time tool invocation polish

**Sub-PRs:**
- `W6.A` — `padosoft/askmydocs-mcp-tool-ecommerce` package (private Pro repo) — Padosoft custom e-commerce business logic
- `W6.B` — Polish: tool-call bubble visual states + transitions + error recovery + retry CTA
- `W6.C` — Parallel tool invocation (multiple tool-call parts in same assistant turn) — Node sidecar uses `Promise.all` for parallel invocation when provider returns multiple `tool_use` in same response
- `W6.D` — Per-conversation tool authorization — UI for user to per-conversation enable/disable each tool (override per-tenant default)
- `W6.E` — Wn closure status doc

**Tests expected:** ~35 new.

**Risk:** **low-medium** — Pro tool is well-scoped business logic; parallel tool invocation needs careful concurrency testing.

### W7 — Patent Box Pro MCP tool + audit dashboard

**Sub-PRs:**
- `W7.A` — `padosoft/askmydocs-mcp-tool-patent-box` package (private Pro repo) — tax dossier auto-fill, R&D classification, Italian compliance prompts
- `W7.B` — Audit dashboard — `McpToolCallAuditView.tsx` with filters + CSV export + per-user / per-tool drill-down
- `W7.C` — PII redactor integration audit — verify every MCP tool input is PII-redacted before persist to `mcp_tool_call_audit.input_json_redacted` (extends `BoundaryCoverageTest`)
- `W7.D` — Tool call telemetry — Prometheus / Statsd hooks for tool-call duration + error rate per tool + per server
- `W7.E` — `rc3` tag — `v5.0.0-rc3` at W7 closure (R39)
- `W7.F` — Wn closure status doc

**Tests expected:** ~35 new.

**Risk:** **medium** — Patent Box tool wires private Padosoft business logic (Lorenzo's own use case = dogfood). Audit dashboard is the largest admin surface of the cycle.

### W8 — RC acceptance + GA merge + closure

**Sub-PRs:**
- `W8.A` — RC acceptance test pack — full E2E suite green + Architecture suite green + cohort regression vs v4.5 baseline + multi-tenant isolation test for MCP servers + audit table
- `W8.B` — Bug-fix iterations from RC acceptance
- `W8.C` — Documentation refresh — README `MCP / Agentic` section + per-tool READMEs polished + v5.0 changelog entry + ADR 0008 ("v5.0 MCP client architecture decision")
- `W8.D` — Node sidecar Docker image published to GHCR (`ghcr.io/lopadova/askmydocs-mcp-client:v5.0.0`)
- `W8.E` — Sister-package version locks — every `padosoft/askmydocs-mcp-tool-*` OS package tagged `v1.0.0` on Packagist
- `W8.F` — `feature/v5.0` → `main` merge per R37 (once-per-major)
- `W8.G` — `v5.0.0` GA tag at merge SHA
- `W8.H` — v5.0 cycle closure doc

**Tests expected:** ~15 (RC bug fixes only).

**Risk:** **low-medium** — same shape as v4.x closures.

---

## 4. Risks + mitigations

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| Node sidecar ops complexity (2 runtimes) | accepted | medium | `supervisord` auto-restart + healthcheck + Docker image with single-command deploy; documentation includes "single-Docker" + "PHP-only fallback" config |
| MCP spec evolution breaks SDK compat | medium | medium | `@modelcontextprotocol/sdk` auto-updates via Renovate / Dependabot; CI Live test pack runs against latest SDK on schedule |
| Credential leakage (encryption mistake) | low | RCE-class | `auth_config_encrypted` MUST use `Crypt::encryptString`; encryption-at-rest test + per-tenant isolation test + audit redaction test |
| Tool authorization granularity insufficient | medium | medium | Per-tool Gate + Spatie role + per-conversation override + audit trail; if requirements grow, switch to attribute-based access control (ABAC) post-v5.0 |
| Latency (chat round-trip through Node sidecar) | medium | UX | Node sidecar runs on localhost (no network hop); `Promise.all` for parallel tool calls; tool-call streaming UI keeps user engaged during invoke |
| MCP server crashes mid-invoke | medium | medium | `ConnectorSyncJob`-style retry/backoff; tool-call audit captures error state for replay; Node sidecar process supervision restarts crashed servers automatically |
| Stdio transport stability (long-running child_process) | medium | medium | Node sidecar manages child_process lifecycle with per-MCP-server limits + auto-respawn on crash |
| FE complexity creep (tool-call UI states) | medium | UX | UI state machine codified in `ToolCallBubble.tsx` as XState chart; comprehensive Playwright coverage per state |

---

## 5. Acceptance criteria

Gates for `v5.0.0` GA (W8 RC acceptance pack):

- [ ] Node sidecar scaffold ships + Dockerfile + supervisord + healthcheck working + Docker image published to GHCR
- [ ] All 3 MCP transports working: stdio + SSE + HTTP streamable (verified per-transport by integration test against fixture MCP server)
- [ ] `McpServerRegistry` + `McpToolAuthorizer` + `ToolInvoker` + `McpClientBridge` implemented + cross-tenant isolation test green
- [ ] Migrations `mcp_servers` + `mcp_tool_call_audit` landed + tenant-scoped composite uniques + multi-tenant isolation R30 test extended
- [ ] Admin SPA: list servers + register new + handshake + tool matrix + audit log all functional + Playwright E2E happy path + 3 failure paths
- [ ] All 5 reference MCP tool packages tagged `v1.0.0` (3 OS on Packagist + 2 Pro in askmydocs-pro)
- [ ] `KbChatController::streamReply()` emits `tools` to provider; tool-call UIMessage chunks render correctly with state machine UI
- [ ] AskMyDocs's own `KnowledgeBaseServer` registered as eat-your-own-dog-food (chat can invoke its own KB-search tool)
- [ ] Per-user / per-tool / per-conversation authorisation matrix working + Spatie permission `invokeMcpTools` per-user gated
- [ ] Tool approval dialog wiring (`addToolApprovalResponse`) for "requires approval" tools
- [ ] `sendAutomaticallyWhen` triggers automatic follow-up turn after tool results land (lastAssistantMessageIsCompleteWithToolCalls predicate)
- [ ] PII redactor wraps every MCP tool input before persist to `mcp_tool_call_audit.input_json_redacted` (BoundaryCoverageTest extension green)
- [ ] Parallel tool invocation works via `Promise.all` in Node sidecar (multi-tool-call E2E green)
- [ ] +250 tests cumulative across the cycle
- [ ] 3 RC tags: `v5.0.0-rc1` at W2, `v5.0.0-rc2` at W5, `v5.0.0-rc3` at W7
- [ ] GA tag `v5.0.0` at W8 closure (R39 + R37)
- [ ] ADR 0008 documents the Node-sidecar architecture decision
- [ ] CI green on `feature/v5.0` HEAD at merge SHA (R36 mandatory Copilot loop + CI green conjunctive)

---

## 6. OS vs Pro matrix

| Component | License | Repo |
|---|---|---|
| Node sidecar (`mcp-client/`) | OS (MIT) | `lopadova/AskMyDocs` core |
| Migrations + Eloquent + admin controllers | OS (MIT) | core |
| `McpClientBridge` + `ToolInvoker` + `McpServerRegistry` + `McpToolAuthorizer` | OS (MIT) | core |
| Admin SPA — `McpToolsView` + RegisterServerDialog + HandshakeStatus + ToolMatrix + Audit | OS (MIT) | core |
| Chat tool-call rendering (FE) | OS (MIT) | core |
| `padosoft/askmydocs-mcp-tool-github` | OS (MIT) | new public repo |
| `padosoft/askmydocs-mcp-tool-slack` | OS (MIT) | new public repo |
| `padosoft/askmydocs-mcp-tool-postgresql` | OS (MIT) | new public repo |
| `padosoft/askmydocs-mcp-tool-ecommerce` | **Proprietary (Pro)** | `padosoft/askmydocs-pro` monorepo |
| `padosoft/askmydocs-mcp-tool-patent-box` | **Proprietary (Pro)** | `padosoft/askmydocs-pro` monorepo |

---

## 7. Branching + release alignment (R37 + R39)

- Cut `feature/v5.0` off `main` after v4.5 GA tag lands on main
- Every sub-PR `feature/v5.0/W{n}.{letter}` targets `feature/v5.0` (NOT main)
- R39 rc tags: `v5.0.0-rc1` after W2, `v5.0.0-rc2` after W5, `v5.0.0-rc3` after W7 — captured at closure-commit SHA
- R37 final merge: `feature/v5.0` → `main` ONCE at W8 closure → tag `v5.0.0` GA
- `padosoft/askmydocs-mcp-tool-*` OS repos tag `v0.x` rcs during W1-W7 and `v1.0.0` at v5.0 GA

---

## 8. Out of scope (deferred to v5.1+ or v6.0)

- **AI Act compliance bundle** — v6.0 (separate dedicated cycle — see PLAN-v6.0)
- **Per-lane adversarial alerting** — v5.5 candidate (small polish cycle if scheduled)
- **TanStack Router unification** — v5.5 candidate
- **Real-time MCP server notification push** (server-initiated tool suggestions) — v5.1+ if demand
- **OAuth-based MCP servers** (vs static credentials) — v5.1+
- **MCP resource browsing** (separate from tool invocation) — v5.1+
- **MCP server marketplace** (community-shared MCP server registry) — v5.2+

---

## 9. Cross-references

- `docs/v4-platform/PLAN-v4.5-connector-framework-and-vercel-sdk-completion.md` — predecessor cycle (connector framework is foundational pattern)
- `docs/v4-platform/PLAN-v6.0-ai-act-compliance.md` — successor cycle (AI Act compliance integration)
- `docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md` — Gap 4 (agentic tool use) justification + competitor landscape
- `app/Mcp/Servers/KnowledgeBaseServer.php` — existing v3 MCP **server** (inward-facing); v5.0 adds the CLIENT direction
- `memory:feedback_v45_strategic_roadmap` — Lorenzo strategic decisions (Node sidecar + paradigm shift rationale)
- `.claude/skills/branching-strategy-feature-vx/` — R37
- `.claude/skills/rc-tag-per-week-milestone/` — R39
- `.claude/skills/security-invariants-atomic-or-absent/` — R21 (credential vault encryption)
- `.claude/skills/cross-tenant-isolation/` — R30 (MCP server table tenant scoping)

---

## 10. Sign-off

This plan was prepared on 2026-05-11 as a planning artefact for the v5.0 cycle. Lorenzo authorised auto-mode kickoff through v4.5 + v5.0 + v6.0 end-to-end (memory `feedback_v45_strategic_roadmap` — Auto-mode roadmap kickoff section). Kickoff sequence after v4.5 GA:

1. Cut `feature/v5.0` off main (post v4.5 GA)
2. Start v5.0 W1 — Node sidecar scaffold + Laravel migration + handshake test
3. Each Wn closes per R39, then v5.0 GA per R37

**Status:** PLAN — pending v4.5 GA.
