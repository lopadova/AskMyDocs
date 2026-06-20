# PLAN — v8.16 · AI FinOps integration (spend governance)

**Cycle:** v8.16 (next minor after v8.15 GA on `main`)
**Integration branch:** `feature/v8.16` (R37) ← sub-branches `feature/v8.16/Wn` (PR target = `feature/v8.16`)
**Packages:** `padosoft/laravel-ai-finops` (core, includes metering/ledger/budgets/policy/forecast/alerts) + `padosoft/laravel-ai-finops-admin` (React admin SPA, cross-mounted)
**Owner decision (2026-06-17):** full SDK migration THIS cycle; renumber v8.14 → v8.16; in-scope = MCP read tools + streaming metering + server-side cost authority + admin SPA E2E.
**Predecessor work:** PR #304 (`feature/v8.14`, bramato + Claude) — a clean, green, R32/R43-compliant **bridge** (`AiCallMeter`) that feeds the package `MeteringListener` after raw `Http::` calls. Rebased + renumbered as the W1 foundation; the bridge is retired to a thin fallback once providers move to the SDK.

---

## Strategic direction

**Standing principle (owner, 2026-06-17): every AI call goes through the `laravel/ai` SDK — now and in the future.** This reverses the locked-in CLAUDE.md §6 decision ("raw `Http::` for OpenAI/Anthropic/Gemini/OpenRouter"). Rationale: the FinOps package hooks `laravel/ai` lifecycle events for metering + pre-flight enforcement; native events give uniform coverage (sync chat + streaming + embeddings + failover) and remove the fragile manual bridge. Captured in a new ADR; §6 + all docs/skills citing "raw Http::" swept (R9).

**End-state data flow:**
```
AiManager → provider() → laravel/ai SDK driver (OpenAI·Anthropic·Gemini·OpenRouter·Regolo)
  → events: PromptingAgent·AgentPrompted·AgentStreamed·GeneratingEmbeddings·EmbeddingsGenerated
  → finops MeteringListener (auto_register) → ai_finops_usage_ledger (cost cascade: actual→computed→estimated→covered)
  → finops EnforcementListener (pre-flight budgets/policy/kill-switch)
HostTenantResolver → TenantContext::current()  (R30/R31 on every ledger row)
```

---

## Wave breakdown

### W1 — Foundation (rebase + land the bridge)  → RC1
- Create `feature/v8.16` from `main` (done). Create `feature/v8.16/W1-foundation`.
- Merge `origin/feature/v8.14` (PR #304) in; resolve v8.15 conflicts on: `.env.example`, `README.md`, `MaintenanceCommandController.php`, `AppServiceProvider.php`, `TierOneSchedulerRegistrar.php`, `config/askmydocs.php`, `docs-site/docs.json`, `AdminAuthorizationMatrixTest.php`.
- Renumber all `v8.14` strings → `v8.16` (README changelog/roadmap, docs-site, CLAUDE.md, .env.example comments).
- Confirm `ai_finops_*` migrations apply on SQLite (tests) + pgsql.
- Keep #304's: secure mount (R32), `HostTenantResolver` (R30/R31), `FinOpsAuthorize` (method-aware view/manage), R43 both-states tests, scheduler slots, gates.
- Bridge metering live for `chat` / `chatWithHistory` / `generateEmbeddings`.
- PR W1 → `feature/v8.16`; R40 local critic → R36 cloud loop → auto-merge → tag `v8.16.0-rc1`.

### W2 — Full SDK migration (core deliverable, biggest risk)  → RC2
- **INVESTIGATE FIRST (owner note 2026-06-17):** Does `laravel/ai` ship a **first-party OpenRouter driver**? Owner believes YES. Confirm in `vendor/laravel/ai/src/Providers/`. The likely real picture: laravel/ai routes OpenAI/Anthropic/Gemini/OpenRouter natively; FinOps' `OpenRouterCostResolver` + global `HttpUsageCaptureMiddleware` **read `usage.cost` from the raw OpenRouter response body** (the *actual billed cost* that laravel/ai's `Usage` DTO does NOT expose). So the OpenRouter migration is NOT "write a custom driver" — it's "use the native driver + ensure FinOps' HTTP capture middleware is active so actual-cost lands in the ledger." Verify which providers are first-party vs need a shim BEFORE writing code.
- Bump `composer.json` `laravel/ai` pin `>=0.6,<0.6.8` → `^0.6.8 || ^0.7` (the package wants this). **Verify breaking changes 0.6.7→0.6.8/0.7 first** — load-bearing risk; the Regolo path already proves 0.6.x SDK end-to-end.
- Migrate `OpenAiProvider` / `AnthropicProvider` / `GeminiProvider` / `OpenRouterProvider` off raw `Http::` onto `laravel/ai` drivers, `RegoloProvider` as template (incl. `Http::fake`-under-SDK testability). Preserve feature parity: streaming, fallback, embeddings, `temperature`/`maxTokens`, keyless/anonymous behaviors.
- Reshape `config/ai.php` provider blocks to SDK shape (config notes this as "W2.B.full follow-up").
- Rewrite the 4–5 provider unit tests (`tests/Unit/Ai/{OpenAi,Anthropic,Gemini,OpenRouter}ProviderTest.php`).
- Flip `ai-finops.hook.auto_register=true`; remove per-call meter calls from `AiManager`; retire `AiCallMeter` to a documented fallback (no double-count — all native now; the `=== 'regolo'` literal guard becomes moot).
- Ensure FinOps `actual_cost` HTTP capture is enabled so OpenRouter billed cost is recorded.
- **ADR reversing CLAUDE.md §6** + R9 sweep of every doc/skill asserting raw `Http::`.
- PR W2 → `feature/v8.16`; loops; tag `v8.16.0-rc2`.

### W3 — Streaming metering + server-side cost authority  → RC3
- Confirm `AgentStreamed` + `hook.stream_meter` meters `chatStream()` / `MessageStreamController` (native once on SDK; else terminal-usage stream wrapper).
- **Fix "costo token messo a caso":** retire static `config/ai.php cost_rates` + FE `computeMessageCost()`; populate a **real per-turn cost server-side** from FinOps `CostResolutionService` at `ChatLogManager` time. Add a `cost` surface to `chat_logs` (additive migration). Tie the ledger row to the chat turn via a real `trace_id` (fixes synthetic-`invocationId` reconciliation gap; use `TraceContext::within()`).
- Surface cost in chat `meta` (R27 additive shape — never rename/sub-objectify existing keys); FE `TokenCostMeter` reads server cost instead of computing.
- PR W3 → `feature/v8.16`; loops; tag `v8.16.0-rc3`.

### W4 — MCP tri-surface + admin SPA E2E + docs/GA  → v8.16.0
- **MCP read tools** (R44): host-side tools on `KnowledgeBaseServer::$tools` over core FinOps services — spend summary, budget status, top models/tenants. Bump MCP registration-count test. R32 matrix for any new route.
- **Playwright E2E** for the cross-mounted FinOps admin SPA (R12/R13, real data, happy + failure path).
- Build/publish SPA assets in the CI workflow step (assets gitignored; `vendor:publish --tag=ai-finops-admin-assets`).
- `docs-site/ai-finops.mdx` refresh (R45, now-honest full coverage incl. streaming + MCP); flip README roadmap row (R "status flip on GA"); update CLAUDE.md §6/§3 + .env.example.
- Merge `feature/v8.16` → `main` (R37, once-per-release); tag **v8.16.0**; GitHub Release.

---

## Cross-cutting rules enforced
R9 docs-match-code · R30/R31 tenant isolation + mandatory tenant_id · R32 RBAC authorization matrix · R36 cloud Copilot loop · R39 RC tag per wave · R40 local critic loop before push · R43 feature-flag both states · R44 tri-surface PHP+HTTP+MCP · R45 doc-site parity · R27 additive response shape.

## Load-bearing risks
1. `laravel/ai` 0.6.7→0.6.8/0.7 breaking changes — gates W2. Verify changelog first.
2. OpenRouter driver — INVESTIGATE (owner: likely first-party + HTTP cost capture; not a custom driver).
3. Reversing §6 — ADR + full R9 doc sweep.
4. Provider feature parity on rewrite — test-driven per provider.
5. PR #304 was CONFLICTING/DIRTY vs main — resolved by the W1 merge.
