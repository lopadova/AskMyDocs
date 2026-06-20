# ADR 0015 — Provider transport migrated to the `laravel/ai` SDK (v8.16)

- **Status:** Accepted
- **Date:** 2026-06-18
- **Cycle:** v8.16 (AI FinOps) — W2
- **Reverses:** the "No AI SDKs for OpenAI / Anthropic / Gemini / OpenRouter"
  decision in `CLAUDE.md §6` (provider transport was raw `Http::`).
- **Builds on:** the Regolo precedent (`padosoft/laravel-ai-regolo` SDK adapter
  on `laravel/ai`, the documented exception since v4.x).

## Context

AskMyDocs historically called OpenAI / Anthropic / Gemini / OpenRouter through
raw `Illuminate\Support\Facades\Http` for full control over auth, retries,
timeouts and response parsing, with `Http::fake()` testability. Regolo was the
lone SDK exception.

v8.16 ships **AI FinOps** (`padosoft/laravel-ai-finops`), whose metering hook
records usage automatically — but **only for calls that flow through the
`laravel/ai` SDK lifecycle events** (`AgentPrompted` / `AgentStreamed` /
`EmbeddingsGenerated`). With four providers on raw `Http::`, the W1 foundation
bridged them into the ledger via an interim host shim
(`App\FinOps\AiCallMeter`). That bridge is duplicative: the SDK already knows
how to talk to every one of these providers natively (verified — all four have
native drivers in `laravel/ai` 0.6.8), and it cannot capture provider-specific
billing signals (e.g. OpenRouter's real `usage.cost`) that the SDK + a finops
HTTP middleware can.

The one thing the SDK cannot host is AskMyDocs's **external-MCP tool loop**
(`McpToolCallingService` passes dynamic JSON-schema tools and replays
`role:'tool'` + `tool_call_id` itself): the SDK owns its own tool loop and only
accepts typed PHP `Tool` classes. See `docs/v4-platform/W2-sdk-migration-findings.md`.

## Decision

Migrate all four providers onto the native `laravel/ai` SDK drivers, with a
per-provider shape driven by whether the provider serves AskMyDocs's tool turn:

1. **Anthropic, Gemini → FULLY SDK.** No tool path in AskMyDocs; Gemini
   embeddings go through the SDK too. Shared adapter logic lives in
   `App\Ai\Providers\Concerns\SdkChat` + `App\Ai\Providers\Internal\SdkAnonymousAgent`.
2. **OpenAI, OpenRouter → HYBRID.** No-tools chat + embeddings go through the
   SDK (metered by the finops lifecycle hook); the MCP **with-tools** turn stays
   on the existing raw `Http::` `/chat/completions` branch (metered by the
   `AiCallMeter` bridge). `McpToolCallingService::TOOL_CAPABLE_PROVIDERS`
   (`openai`, `openrouter`) is unchanged.
3. **Metering reconciliation (double-count guard).** `AiManager` owns the gate:
   the bridge is invoked ONLY for the residual raw-Http with-tools turn
   (`SDK_HYBRID_TOOL_PROVIDERS` + `bridgeShouldMeterChat`); every SDK-path call
   (anthropic/gemini chat, openai/openrouter no-tools chat, all SDK embeddings)
   is metered by the hook and skipped by the bridge.
4. **OpenRouter real-cost capture.** The SDK call sets `usage: { include: true }`
   (via `SdkChat::sdkProviderOptions()` → `SdkAnonymousAgent` implementing
   `HasProviderOptions`) so OpenRouter returns the billed `usage.cost`. The
   finops actual-cost capture reads it when `AI_FINOPS_ACTUAL_COST=true`
   (default **OFF** — observe-first, R43).
5. **Config shape.** Every provider's `config/ai.php` block moves to the SDK
   shape (`driver` / `key` / `url` / `models.*`). For the hybrid providers the
   raw-Http branch reads the same keys — one source of truth.

`laravel/ai` stays pinned at `^0.6.8` for W2 (regolo 1.0.1 is `^0.6`-only); the
`||^0.7` widening is gated on a 0.7-compatible regolo release.

## Consequences

- **Positive.** Uniform SDK transport; finops meters every provider natively;
  OpenRouter real-cost available; the interim `AiCallMeter` bridge shrinks to
  the residual with-tools turn (its "retire to fallback" end-state).
- **Negative / watch-outs.** Provider tests fake the SDK wire shapes now (OpenAI
  no-tools → `/responses` `output[]`; OpenRouter → `/chat/completions`
  `choices[]`); a shared `TestCase::openAiSdkResponsesBody()` helper centralises
  the OpenAI `/responses` body. The SDK owns retry/error-mapping, so a future
  SDK bump can change wire behaviour — the provider adapter tests pin the
  AskMyDocs-facing contract (DTO mapping, header auth, tool replay) to catch drift.
- **The tool turn is deliberately NOT on the SDK.** Hosting AskMyDocs's external
  MCP loop on the SDK needs a `Tool`-adapter + `maxSteps=1` design — a separate
  ADR, not this wave.
