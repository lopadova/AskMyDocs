# W2 — Full SDK migration: investigation findings + build plan

Read-only investigation done 2026-06-18 (agent). Source of truth for the W2 wave.
Do NOT start W2 implementation until W1 (PR #314) merges into `feature/v8.16`.

## TL;DR
Migrate OpenAI / Anthropic / Gemini / OpenRouter from raw `Http::` to the `laravel/ai`
SDK, using `RegoloProvider` as the template, so FinOps meters automatically via the SDK
event hook (`AgentPrompted` / `AgentStreamed` / `EmbeddingsGenerated`) and the interim
`AiCallMeter` bridge retires to a fallback.

## What the SDK already gives us
- Installed: `laravel/ai v0.6.7`. Host pin: `">=0.6,<0.6.8"` (composer.json) — the cap is the
  deliberate hold point. FinOps wants `^0.6.8 || ^0.7`. **Lift the cap in W2.**
- **All 4 providers have NATIVE drivers** under `vendor/laravel/ai/src/Providers/` +
  gateways `src/Gateway/<Name>/`: `openai`, `openrouter`, `anthropic`, `gemini`. Regolo is the
  only non-native one (added by `padosoft/laravel-ai-regolo`) — exactly the extension pattern.
- Public API: agent `->prompt($prompt,$attachments,$provider,$model,$timeout): AgentResponse`
  (`Promptable.php:49`); streaming `->stream(...) : StreamableAgentResponse` (iterable of
  `Streaming/Events/*`); embeddings `Embeddings::for($texts)->generate($provider,$model)`.
- **Response DTO carries NO cost** — `Responses/Data/Usage.php` is token counts only
  (prompt/completion/cacheWrite/cacheRead/reasoning). `grep cost vendor/laravel/ai/src` = 0 hits.

## How FinOps captures REAL cost (survives the migration)
- Global Http RESPONSE middleware: `LaravelAiFinOpsServiceProvider::bootActualCostCapture()`
  registers `HttpUsageCaptureMiddleware` via `Http::globalResponseMiddleware(...)`.
- The middleware reads the raw response body and, when it sees an OpenRouter-shaped
  `usage.cost` numeric field, buffers `{cost,currency:'credits',tokens...}` into the
  request-scoped `RawResponseCapture`. Matches by **body shape, not host** (response
  middleware can't see the URL).
- `OpenRouterCostResolver::resolve()` drains the buffer, credits→base currency, optional
  authoritative USD via `GET /api/v1/generation?id=`. Top of the cost cascade in
  `MeteringListener::baseEnvelope()`: **actual billed → tokens×tariff → estimated**.
- Works post-migration because laravel/ai gateways use `Illuminate\Http\Client` → traffic
  passes through `Http::globalResponseMiddleware`. Capture is transport-level, not DTO-level.

## TWO load-bearing gates (must handle or "real cost" silently never lands)
1. `config('ai-finops.pricing.actual_cost.enabled')` defaults **false** (`AI_FINOPS_ACTUAL_COST`).
   `bootActualCostCapture()` early-returns when off. Host `config/ai-finops.php` must enable it.
2. OpenRouter only RETURNS `usage.cost` when the request sends `usage:{include:true}`.
   laravel/ai's OpenRouter gateway does NOT add it (only `stream_options.include_usage` on the
   streaming path = token flag, not cost). Inject via the agent's
   `providerOptions($driver)` seam (`Gateway/OpenRouter/Concerns/BuildsTextRequests.php:51-55`
   merges providerOptions into the body). **Ship an OpenRouter agent whose `providerOptions()`
   returns `['usage'=>['include'=>true]]`.** Today's raw provider also omits it — so verify in
   W3 whether cost is actually captured today at all.

## Template: RegoloProvider (app/Ai/Providers/RegoloProvider.php)
- ctor takes `config('ai.providers.regolo')` in SDK shape (`driver/key/url/models.text.default/...`).
- `makeAgent()` builds a `RegoloAnonymousAgent` (subclass of SDK `AnonymousAgent` in
  `app/Ai/Providers/Internal/`) carrying `instructions/messages/tools/maxTokens/temperature` so
  per-call max_tokens/temperature reach `TextGenerationOptions::forAgent()`. Without the subclass
  per-call options silently drop (the documented generateTitle regression).
- `toAiResponse(AgentResponse)`: text→content, meta.model→model, usage.prompt/completionTokens
  (0→null), finishReason from `steps->last()` (skip mid-loop tool steps).
- Embeddings: `Embeddings::for($texts)->generate($name, $cfg.models.embeddings.default)`.
- **Streaming NOT native yet** — delegates to `FallbackStreaming::streamFromChat()`. Gap.

## Per-provider migration notes (risks)
- **OpenAI** — lowest risk; native, OpenAI-compatible, embeddings ok. Verify SDK tool-call
  mapping (`MapsTools`/`ParsesTextResponses`) yields equivalent `AiResponse->toolCalls`.
- **OpenRouter** — cost-critical: inject `usage:{include:true}` via providerOptions; re-apply
  `HTTP-Referer`/`X-Title` headers through the SDK client seam; tool-call mapping.
- **Anthropic** — NO embeddings (throws today) → keep `supportsEmbeddings()=false` +
  `EMBEDDINGS_FALLBACK_ORDER` intact; distinct wire shape (system top-level, mandatory
  max_tokens, input/output_tokens, stop_reason) → verify SDK gateway parity.
- **Gemini** — confirm SDK `gemini` gateway auths via `x-goog-api-key` HEADER (not URL query —
  R-logging-security); role remap assistant→model; 768-dim embeddings → keep dimension-safety
  warnings in AiManager working.

## Config reshape
- config/ai.php: openai/anthropic/gemini/openrouter blocks are LEGACY shape
  (`api_key/base_url/chat_model/...`). Reshape each to SDK shape (`driver/key/url` + model/options
  at agent layer) — regolo block already shows the target.
- `cost_rates` block (config/ai.php:74-101) = the static USD/1M-token guess served via
  `GET /api/chat/cost-rates` → W3 replaces it with FinOps real cost.

## AiCallMeter retirement
- `app/FinOps/AiCallMeter.php` is invoked manually from `AiManager.php:137,151,176` after
  chat/chatWithHistory/generateEmbeddings (streaming NOT metered, :165). Regolo excluded
  (`shouldMeter()` false) to avoid double-count.
- Once the 4 providers go through the SDK, finops `bootMeteringHook()`
  (`LaravelAiFinOpsServiceProvider.php:205-224`) auto-meters via the SDK events → the manual
  AiCallMeter calls become redundant → reduce to fallback (or remove) + flip `auto_register` on.

## W2 build order (proposed)
1. Fetch upstream laravel/ai 0.6.8 + 0.7 release notes; confirm breaking changes (no vendored
   changelog). Then bump composer pin `^0.6.8 || ^0.7`, `composer update laravel/ai`.
2. Reshape config/ai.php providers.{openai,anthropic,gemini,openrouter} to SDK shape (+ .env.example, R6).
3. Port providers one at a time (OpenAI → OpenRouter → Anthropic → Gemini), each with its
   `<Name>AnonymousAgent` + `to*Response` mapper, mirroring RegoloProvider. Keep AiProviderInterface.
4. Enable actual_cost in config/ai-finops.php; OpenRouter agent providerOptions usage.include.
5. Rewrite the 4-5 provider unit tests (SDK fakes instead of Http::fake()).
6. Flip `auto_register`; retire AiCallMeter to fallback (keep for any still-raw path).
7. ADR reversing CLAUDE.md §6 ("no SDKs for OpenAI/Anthropic/Gemini/OpenRouter") + R9 doc sweep
   of every "raw Http::" reference (CLAUDE.md, README, copilot-instructions, skills, ADRs).
8. Full suite green → R40 critic → push → R36 loop → merge → tag v8.16.0-rc2.

## Upstream laravel/ai changelog 0.6.8→0.7.2 (fetched 2026-06-18, github.com/laravel/ai/releases)
- **0.6.8** (11 May): sub-agents-as-tools; configurable conversation/message table names; Gemini
  `cachedContent` via provider options; OpenRouter TTS/STT; **provider options in embeddings**;
  Anthropic `pause_turn` continuations; stream failover during iteration. No breaking changes.
- **0.7.0** (19 May): **BREAKING — OpenAI strict mode is now opt-in via `@Strict` attribute**
  (was default). Low impact for us: we don't use strict structured-output on the chat path.
  Also: validation rejects blank inputs for embeddings/reranking/image; **"Usage details captured
  consistently across OpenAI-shaped providers"** (helps token metering); generic types on
  conversation relations; DB connection config for Conversation models.
- **0.7.1** (26 May): Gemini `gemini-3.5-flash` smartest default; Bedrock cache usage in responses;
  Gemini image-gen fix + deprecated-model updates. Model-default changes only (we set explicit models).
- **0.7.2** (28 May): Anthropic `claude-opus-4-8` smartest default; reverted failover model-attr.
- **Pin decision:** `^0.6.8 || ^0.7` (matches finops backbone req; keep flexible). `composer update
  laravel/ai` then run the full suite. The strict-mode change is the only thing to verify.

## Streaming + cost authority = W3 (not W2)
SDK-native `stream()` mapping into AskMyDocs StreamChunk + AgentStreamed metering + server-side
CostResolutionService at ChatLogManager time + additive chat_logs.cost column.
