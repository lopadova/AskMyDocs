# ADR 0016 — `laravel/ai` platform migrated to the 0.8 line (v8.19)

- **Status:** Accepted
- **Date:** 2026-06-21
- **Cycle:** v8.19 — W1
- **Builds on:** ADR 0015 (all provider transport on the `laravel/ai` SDK since
  v8.16). Closes the 0.7/0.8 bump deferred through v8.16–v8.18.

## Context

Since ADR 0015 the host has pinned `laravel/ai:^0.6.8`. The 0.7/0.8 bump was
repeatedly deferred: the SDK surface was untested across the five providers, and
`padosoft/laravel-ai-regolo` originally pinned `^0.6`. v8.19 integrates
`padosoft/laravel-ai-guardrails`, whose core **requires `laravel/ai ^0.8`** — so
the bump became a hard precondition, and the user directed a **total** migration
(every padosoft package that touches the SDK released on `^0.8`, then the host
bumped, no version skew, no mixed 0.7.x/0.8.x).

### Breaking-change surface (0.6 → 0.8)

A pre-flight audit (a `Laravel\Ai` namespace grep across the 23 installed
`padosoft/*` packages) found only **two** packages reference the SDK in code:
`laravel-ai-regolo` (the adapter) and `laravel-ai-finops` (the metering
listeners). The single behavioural break between 0.6 and 0.8 is the
`TranscriptionGateway::generateTranscription()` contract gaining a
`$providerOptions` parameter in **laravel/ai v0.7.0** (laravel/ai#31). The
chat and embeddings contracts the host actually uses were unchanged — verified
empirically: bumping to 0.8.1 left every provider / embeddings / AiManager /
regolo / FinOps test green with **zero host code changes**.

## Decision

1. **Sister packages first.** `padosoft/laravel-ai-regolo` v1.2.1 supports
   `^0.6|^0.7|^0.8.1` (the TranscriptionGateway `$providerOptions` alignment);
   `padosoft/laravel-ai-finops` v1.4.0 is verified against the 0.8 line. Both
   were released on their own repos before the host consumed them.
2. **Host bump.** `composer.json` `laravel/ai` `^0.6.8` → **`^0.8.1`**, regolo
   `^1.0.1` → `^1.2.1`, finops `^1.3` → `^1.4`. `composer update` resolves a
   single coherent `laravel/ai 0.8.1` with no conflict (composer.lock is
   gitignored → CI resolves fresh).
3. **No host code change.** AskMyDocs uses chat + embeddings only (never
   transcription), so the one 0.7 break does not reach the host. The hybrid
   raw-`Http::` MCP with-tools turn (ADR 0015) is unaffected.
4. **Guard.** `tests/Unit/Ai/LaravelAiPinTest` is flipped from "host stays on
   ^0.6" to "host is on the 0.8 line" — guarding against an accidental
   downgrade back to 0.6/0.7 or an untested jump to 0.9/1.0.

## Consequences

- The guardrails core (`^0.8`) is now installable (unblocks W2/W3).
- The padosoft AI ecosystem is coherent on the 0.8 line — no version skew.
- Any future move to `laravel/ai ^0.9`/`^1.0` requires a fresh provider
  compatibility pass before the pin moves (the guard test enforces this).
- If AskMyDocs ever adds an audio-transcription path, it must pass
  `$providerOptions` per the 0.7 contract.
