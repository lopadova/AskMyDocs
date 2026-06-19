# PROGRESS — v8.16 · AI FinOps  (live tracker, update as you go)

Authoritative plan: `PLAN-v8.16-ai-finops.md`. This file = current state for resume across context windows.

## Status legend
⬜ not started · 🟡 in progress · ✅ done · 🔵 blocked/waiting

## Branches (IMPORTANT naming gotcha)
- `feature/v8.16` (integration) — created from main @ 39f90876, pushed to origin.
- Sub-branches MUST use a **hyphen**: `feature/v8.16-W1-foundation`, `feature/v8.16-W2-...`, etc.
  A nested ref (integration name, then a path separator, then the wave) is refused by Git/GitHub once
  `feature/v8.16` already exists as a branch — the parent occupies the ref-file slot that the nested
  form would need as a directory. So every wave uses the flat hyphenated form. PR target stays
  `feature/v8.16` (R37).
- W1 branch: `feature/v8.16-W1-foundation` @ a2912d5b. PR **#314** → feature/v8.16.

## Local env
- composer + php are Herd `.bat` shims, available in **PowerShell** (NOT bash): `composer`,
  `php85`. Tests: `php85 -d memory_limit=1G vendor/bin/phpunit ...` (1G needed for full suite).
- FinOps packages installed locally: `padosoft/laravel-ai-finops v1.2.1` + `-admin v1.2.0`
  (from Packagist). `composer.lock` is GITIGNORED — CI does fresh `composer install` each run.
- #304 CLOSED (superseded by #314).

## Waves
- **W1 Foundation (rebase #304 + bridge)** — ✅ DONE. PR #314 merged into feature/v8.16 @
  **fbd46476** (2026-06-18). Tagged **v8.16.0-rc1** (prerelease) at that SHA. Closed after 6 Copilot
  rounds; the substantive catch was the laravel/ai pin conflict (see the MUST-FIX line below).
  W2 branch `feature/v8.16-W2-sdk-migration` created from fbd46476.
  - [x] W1 branch created (`feature/v8.16-W1-foundation` — hyphen form per the Branches note above — from origin/feature/v8.16)
  - [x] Merge origin/feature/v8.14 — only README.md conflicted (changelog); resolved newest-first. Committed 21410abc.
  - [x] Renumber v8.14 → v8.16 (README header+changelog, .env.example, CLAUDE.md §3, bootstrap/app.php comment)
  - [x] Verified all FinOps additions survived merge (scheduler slots, matrix rows, gates, alias, docs.json nav)
  - [x] composer install finops packages locally (v1.2.1 / v1.2.0)
  - [x] Local tests green: tests/Feature/FinOps + AdminAuthorizationMatrixTest = 15 tests, 276 assert
  - [x] R40 local critic (code-reviewer subagent; copilot-cli was 402/out-of-budget per #304) — fixed 1 must-fix (incomplete v8.14→v8.16 sweep in MaintenanceCommandController + 5 files) + changelog tense
  - [x] PR #314 opened → feature/v8.16, reviewer copilot-pull-request-reviewer
  - [x] ✅ **CI investigation RESOLVED (R22, artefact-first) — there was NO real failure.** The
        red `gh pr checks` rows were a **cancelled duplicate run**, not a test failure. tests.yml
        fires on BOTH `push` and `pull_request`; a `concurrency` group keyed on the head SHA cancels
        one of the two (documented in the workflow's own comment block). The `pull_request` run
        (`27720074143`, then `27724334032`) ran the FULL suite green (PHPUnit 8.3/8.4/8.5 + Playwright
        + Vitest + RAG, 8–18 min each). The `push` run (`27720068198`, conclusion=**cancelled**)
        fast-failed in 3–8 s because it was cancelled at startup — never ran a test. Verified both
        runs share head SHA f32b6c0a. `mergeStateStatus=UNSTABLE` is purely the cancelled run
        attached as a non-success check; merge is not blocked. Lesson: read `conclusion`
        (cancelled≠failure), not the `gh pr checks` fail label.
  - [x] Copilot R3 review: 1 nit (FinOpsAuthorize docblock — `isMethodSafe()` also treats TRACE as
        safe per RFC 7231/Symfony). Fixed in b0e97cda, re-requested review.
  - [x] ✅ **MUST-FIX (Copilot R4): composer `laravel/ai` pin conflict.** App pinned
        `laravel/ai >=0.6,<0.6.8` while finops 1.2.1 requires `^0.6.8 || ^0.7` — with no committed
        lock (gitignored), CI silently resolved finops **1.2.0** (loose) + laravel/ai 0.6.7, NOT the
        intended 1.2.1. Bumped pin to `^0.6.8` (minimal; defers `||^0.7` to W2). **This surfaced a
        SECOND break:** laravel/ai 0.6.8 added `array $providerOptions = []` to the
        `EmbeddingGateway::generateEmbeddings()` interface (changelog "provider options in
        embeddings"); `padosoft/laravel-ai-regolo` **1.0.0**'s gateway lacked it → signature-
        incompatibility fatal in RegoloProviderTest. **regolo v1.0.1** (published after 0.6.8) adds
        the param — bumped regolo pin `^1.0` → `^1.0.1`. Local slice (tests/Unit/Ai +
        tests/Feature/FinOps + AdminAuthorizationMatrixTest) = **127 tests / 516 assert GREEN** on
        laravel/ai 0.6.8 + regolo 1.0.1 + finops 1.2.1. NB regolo 1.0.1 constraint is `^0.6` (allows
        0.6.8, NOT 0.7) — W2's `||^0.7` widening will need a 0.7-compatible regolo release.
  - [x] Copilot R4 nit: AiCallMeter::meterEmbeddings now passes real `$response->embeddings` through
        (COW-cheap, faithful envelope). Pre-existing low-sev `symfony/yaml` advisories (CVE-2026-45133/
        45304/45305, fix in 8.0.12+) noted for a separate hardening pass — unrelated to this PR.
  - [ ] R36 cloud loop until 0 must-fix + CI green → auto-merge (R: auto-merge when ready)
  - [ ] tag v8.16.0-rc1 at the W1 closure SHA on feature/v8.16 (R39)
- **W2 Full SDK migration** — 🟡 IN PROGRESS (branch `feature/v8.16-W2-sdk-migration`)
  - **SCOPE DECISION:** stay on `laravel/ai ^0.6.8` for W2 (do NOT widen to `||^0.7`). regolo 1.0.1
    is `^0.6`-only; 0.6.8's native gateways suffice for the migration. The 0.7 jump stays gated on a
    regolo-0.7 release (separate follow-up). Pin bump already done in W1.
  - **OPEN CRITICAL QUESTION (investigating):** tool-calling. `app/Mcp/Client/McpToolCallingService.php`
    runs AskMyDocs's OWN tool loop — passes dynamic JSON-schema tools via `$options['tools']` into
    `chatWithHistory`, reads back `AiResponse->toolCalls`, executes via MCP, re-calls. Consumers:
    MessageController, MessageStreamController, WidgetOrchestratorService, HostBridge. The SDK normally
    OWNS the tool loop (executes PHP Tool classes), which conflicts. RegoloProvider template sidesteps
    this (tools:[]). A subagent is investigating whether the SDK can return RAW single-turn tool calls
    over dynamic JSON tools without auto-executing (verdict A mechanical / B hard-mismatch / C hybrid:
    no-tools+embeddings→SDK, with-tools→keep Http::). This determines the OpenAI/OpenRouter port shape.
  - [x] INVESTIGATE laravel/ai OpenRouter native driver + FinOps HTTP cost capture — DONE (see
        `W2-sdk-migration-findings.md`): all 4 providers have native drivers; FinOps captures real
        `usage.cost` via a global Http RESPONSE middleware (survives migration); 2 gates: actual_cost
        default-OFF + OpenRouter needs `usage:{include:true}` via agent providerOptions.
  - [x] Verify laravel/ai 0.6.8/0.7 breaking changes; bump pin — pin at ^0.6.8 (W1). 0.7 deferred.
  - [x] TOOL-CALLING VERDICT = (C) HYBRID (see W2-findings). Anthropic/Gemini full-SDK; OpenAI/
        OpenRouter SDK no-tools + Http tools. Metering hook ON by default (regolo proves it).
  - Per-provider migration commits (on branch, not pushed; one PR when complete):
    - [x] **Commit 1 — Anthropic** (50e6a81e): full SDK, no tools/embeddings. Shared `Concerns\SdkChat`
          trait + `Internal\SdkAnonymousAgent`. config SDK shape. AiCallMeter SDK_METERED_PROVIDERS
          += anthropic (data-provider test). 10 provider tests + 128 AI/FinOps slice green.
    - [x] **Commit 2 — Gemini**: full SDK chat + embeddings via SDK (`Embeddings::for()->generate()`
          in `SdkChat::embeddingsViaSdk`). Reshaped config to SDK shape (driver/key/url/models) +
          AiManager hasApiKey (gemini api_key→key) + EmbeddingCacheService model path
          (embeddings_model→models.embeddings.default) + AiManagerTest fallback keys. Added gemini to
          SDK_METERED_PROVIDERS (+ data-provider test). GeminiProviderTest rewritten as SDK-adapter
          contract. AI + FinOps slice = 129 tests green.
    - [x] **Commit 3 — OpenAI** (HYBRID): SDK no-tools chat (`/responses`) + SDK embeddings;
          KEEP raw-Http:: `/chat/completions` with-tools branch (`chatViaHttpWithTools`). config →
          SDK shape (driver/key/url/models). AiManager metering gate (`SDK_HYBRID_TOOL_PROVIDERS`,
          `bridgeShouldMeterChat`): bridge fires only on the with-tools turn; no-tools chat +
          embeddings are SDK-hook-metered (double-count guard). AiCallMeter un-`final` (R26 Mockery).
          Migration tax fixed: hasApiKey openai→.key, EmbeddingCacheService openai model path, +
          6 feature-test fakes reshaped to the SDK `/responses` body (new `TestCase::openAiSdkResponsesBody`
          helper) + Message*/HealthCheck/AiInsights/TabularReview/Workflow config shapes. **FULL
          SUITE GREEN: 2803 tests, 10419 assertions** on laravel/ai 0.6.8.
    - [x] **Commit 4 — OpenRouter** (HYBRID): SDK no-tools chat + SDK embeddings (both OpenAI-compatible
          `/chat/completions` + `/embeddings`); KEEP raw-Http:: with-tools branch. config → SDK shape
          (driver/key/url/http_referer/x_title/models). Cost capture: `SdkAnonymousAgent` now implements
          `HasProviderOptions`; `SdkChat::sdkProviderOptions()` hook (default []) overridden by
          OpenRouterProvider → `usage:{include:true}` so OpenRouter returns real billed `usage.cost`.
          AiManager: openrouter added to `SDK_HYBRID_TOOL_PROVIDERS` + hasApiKey openrouter→.key.
          EmbeddingCacheService openrouter model path. SDK sends `HTTP-Referer`+`X-OpenRouter-Title`;
          raw with-tools branch keeps legacy `X-Title`. Tests: OpenRouterProviderTest rewritten (both
          branches + usage.include assertion); AiManager openrouter gate tests; cache round-trip +
          fallback config shapes. **FULL SUITE GREEN: 2804 tests, 10425 assertions.**
    - [x] **Commit 5 — cleanup/docs**: AiManager metering gate finalised (C3/C4). actual_cost: the
          package already env-gates `AI_FINOPS_ACTUAL_COST` (default OFF, R43); OpenRouter SDK call
          sends `usage:{include:true}` so flipping it captures real cost — no host config override
          (shallow-merge would clobber the package `pricing` block). auto_register already default-ON
          (the SDK hook meters every SDK-path call). **ADR 0015** records the §6 reversal. R9 doc sweep:
          CLAUDE.md §1/§5/§6/§9 + §3 AiCallMeter line, .github/copilot-instructions.md §1/§5, README
          (4 provider-transport claims), .env.example AI_FINOPS_ACTUAL_COST note.
  - [x] Migrate OpenAI/Anthropic/Gemini/OpenRouter to SDK (commits 1–4)
  - [x] Reshape config/ai.php; rewrite provider unit tests
  - [x] auto_register on (default); AiCallMeter retired to the residual with-tools turn only
  - [x] ADR reversing §6 (ADR 0015) + R9 doc sweep
  - [x] PR **#316** → feature/v8.16 — MERGED @ **fbc2d594** (2026-06-18). R36 loop: 6 Copilot rounds
        (1 real must-fix = the MCP final-turn tool-history routing bug Copilot caught that the local
        critic missed; rest should-fix/nit), final review clean (0 comments). Full CI green
        (PHPUnit 8.3/8.4/8.5 + Vitest + RAG + Playwright). CI note: the push+pull_request double-trigger
        cross-cancels Playwright on the same SHA; re-run the surviving run's failed jobs uncontested to
        get a SUCCESS conclusion (R38-adjacent CI-config quirk, not a code failure).
  - [x] tag v8.16.0-rc2 at the W2 closure SHA (fbc2d594) on feature/v8.16 (R39)
- **W3 Streaming + server-side cost authority** — 🟡 IN PROGRESS (branch `feature/v8.16-W3-cost-authority`)
  - **Blueprint (Explore agent):** `ChatLogManager::log(ChatLogEntry)` → `DatabaseChatLogDriver::store()`.
    FinOps cost = `Padosoft\LaravelAiFinOps\Pricing\Cost\CostResolutionService::resolve(AiCallEnvelope,
    TokenUsage, $promptText, $completionText): Resolution` → `$resolution->cost->{total,currency}` +
    `->method->value`. Ledger correlation column = `ai_finops_usage_ledger.trace_id`; set the ambient
    trace via `Padosoft\LaravelAiFinOps\Support\TraceContext::within(['traceId'=>$id], fn)` BEFORE the
    `AiManager::chat()` so the SDK-hook / AiCallMeter bridge ledger row uses it. config cost_rates read
    by `ChatExtrasController:69` (`GET /api/chat/cost-rates`). FE `MessageMetadata`
    (`frontend/src/features/chat/chat.api.ts:119`) has NO cost field yet.
  - [x] **W3.1 backend cost persistence** (this branch): migration
        `2026_06_18_000001_add_cost_to_chat_logs_table` (+ test mirror `0001_01_01_000052`) adds
        `cost` decimal(18,8) + `cost_currency` char(3) + `trace_id` string(64, indexed), all nullable
        additive. `App\FinOps\ChatTurnCostResolver` (+ `ChatTurnCost` DTO) — guarded + try/catch +
        config-gated, mirrors MeteringListener's cascade. `DatabaseChatLogDriver` resolves + persists
        cost (both normal + anonymous paths; anonymous prices from tokens only, no text). ChatLog model
        fillable + `cost`=decimal:8 cast. `ChatLogEntry` += `traceId`. Tests: ChatLogManagerTest cost +
        finops-off + trace_id; ChatTurnCostResolverTest (R43 both states). **PR #318 MERGED into
        feature/v8.16 @ 429fd546** (2026-06-19) — Copilot APPROVED after 5 review rounds. Real catches
        beyond the critic: (a) **metering gate** — resolver requires `ai-finops.metering` so the
        price cache is warm from the hook → NEVER a cold-cache price-feed HTTP fetch on the response
        path (surfaced by WidgetHostToolsTest whose broad Http::fake captured the stray fetch);
        (b) **synthetic-turn guard** — `isSyntheticTurn()` skips refusal/error logs (provider/model
        `none`) which never warmed the cache; (c) money as decimal STRING (8dp) not float; (d) char(3);
        (e) trace_id threaded into the envelope + anonymous-path test with a resolver spy proving no
        PII text is priced. CI-ops note: self-healing monitor `gh run rerun <run> --failed` on the
        cross-cancelled Playwright until SUCCESS.
  - [x] **W3.2 backend — cost in meta + trace correlation** (branch `feature/v8.16-W3.2-cost-meta-fe`):
        new `App\FinOps\ChatTraceContext` (finops-guarded `TraceContext::within` wrapper, key `trace_id`,
        + `newTraceId()`). KbChatController: per-turn trace id, wrap `AiManager::chat()` in the trace
        context, resolve cost via ChatTurnCostResolver, surface `cost`/`cost_currency` in `meta` (R27
        additive — present on success + both refusal paths, null sentinel); thread `traceId` into the
        success + sentinel ChatLogEntry. MessageController: same, wrapping the whole MCP tool loop
        (`chatWithTools`) so every metered call + chat_logs row share one trace_id; cost in the assistant
        message metadata. Tests: KbChatResponseShapeTest + MessageControllerTest assert the additive cost
        keys. **FULL SUITE GREEN: 2823 tests, 10472 assertions.** Commits 18887746 (progress) / 2cd23cc2
        (KbChat) / cca93004 (Message).
  - [x] **W3.3.A — FE reads server cost** (branch `feature/v8.16-W3.3-streaming-fe`, commit 909c4832):
        `MessageMetadata` += `cost?`/`cost_currency?`. `TokenCostMeter` now prefers the server-resolved
        cost (uses it as the authoritative cost, display-formatted; skips the `/api/chat/cost-rates` fetch + client compute entirely);
        legacy rate compute is the fallback for legacy rows / metering-off. `formatCost` currency-aware
        (USD→`$`, else `1.23 EUR`), backward-compatible. MessageBubble passes `meta.cost`/`cost_currency`.
        Vitest: server-cost-wins + zero-cost + non-USD (17 in file; 244 chat FE tests green). Existing
        cost-meter E2E (`chat-w7-sdk-ui.spec.ts:77`) still passes (backward-compatible).
  - [x] **W3.3.B — streaming cost + trace** (branch `feature/v8.16-W3.3b-streaming`):
        MessageStreamController — one `ChatTraceContext::newTraceId()` per streamed turn wraps BOTH the
        MCP `chatWithTools` tool path AND the `chatStream()` foreach (iterated INSIDE
        `ChatTraceContext::within` via a by-ref closure so the lazily-fired metering hook stamps the
        ledger trace_id); resolve cost via `ChatTurnCostResolver` on the grounded path + surface
        `cost`/`cost_currency` in the persisted streaming message metadata (R27 additive; null on the
        sentinel + pre-LLM refusal for shape uniformity); thread `traceId` into the grounded ChatLogEntry.
        MessageStreamControllerTest asserts the additive cost keys on the streamed message. **FULL SUITE
        GREEN: 2828 tests, 10485 assertions.** (Streaming `chat_logs.cost` already resolved via the W3.1
        driver; the metering for the fallback stream path fires through the underlying sync chat → SDK hook.)
  - [ ] **W3.3.C — Playwright E2E + rc3**: E2E asserting the meter shows a SERVER cost (stub a message
        with `metadata.cost`). Static `config/ai.php cost_rates` + `/api/chat/cost-rates` stay as the FE
        fallback (already deprecated-in-practice since the FE prefers server cost). Then tag `v8.16.0-rc3`.
- **W4 MCP + SPA E2E + docs/GA** — ⬜
  - [ ] MCP read tools + registration-count test
  - [ ] Playwright E2E finops admin SPA
  - [ ] SPA asset build/publish in CI
  - [ ] docs-site + README roadmap flip + CLAUDE.md
  - [ ] merge feature/v8.16 → main; tag v8.16.0; Release

## Owner notes (do not lose)
- 2026-06-17: ALWAYS via laravel/ai SDK, forward standard. Reverse §6.
- 2026-06-17: OpenRouter — laravel/ai likely implements it natively; FinOps hooks the HTTP request to capture OpenRouter's extra returned info (usage.cost / billed cost) that laravel/ai doesn't capture. Investigate deeply at W2 before assuming a custom driver is needed.
- "costo token messo a caso" = static config/ai.php cost_rates + FE client-side compute; no server-side cost; fixed in W3.

## Log
- 2026-06-17: design approved; feature/v8.16 created; plan + progress committed.
