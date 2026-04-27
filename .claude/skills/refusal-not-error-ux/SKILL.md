---
description: Use when implementing a deterministic refusal path (anti-hallucination, quota guard, cost short-circuit) that must skip an expensive external call. Enforces shouldNotReceive proof, exact-match sentinel detection, and role=status (NOT alert) UX.
---

# Refusal-Not-Error UX

When the system intentionally refuses to call an expensive external service (LLM, OCR, paid API) because local conditions don't warrant it, the refusal is a QUALITY signal — NOT an error. Three invariants:

## 1. Prove the no-call with `shouldNotReceive`

```php
public function test_refusal_does_not_call_the_LLM(): void {
    $ai = Mockery::mock(AiManager::class);
    $ai->shouldNotReceive('chat');  // ← THE invariant
    $this->app->instance(AiManager::class, $ai);
    // ... trigger refusal path ...
}
```

`Http::assertNothingSent()` is NOT equivalent — it only catches calls through `Illuminate\Http\Client`. A future provider using direct cURL or Guzzle bypasses it. `shouldNotReceive` is transport-agnostic and fails LOUDLY on regression.

## 2. Sentinel detection: `=== trim()` only, never `str_contains`

If the LLM emits a literal sentinel for self-refusal (e.g. `__NO_GROUNDED_ANSWER__`):

```php
private function isSelfRefusalSentinel(string $content): bool {
    return trim($content) === self::SELF_REFUSAL_SENTINEL;
}
```

NOT `str_contains` — substring matching discards partial answers. An LLM saying "I had to fall back to `__NO_GROUNDED_ANSWER__` for the second half" should pass through to the user with the partial answer; only a BARE sentinel (whitespace tolerance OK) means total refusal.

## 3. UX: `role="status"` + `aria-live="polite"`, NOT `role="alert"`

Refusal is information, not error. Visually neutral (info icon, grey/neutral colours), `role="status"`, polite ARIA announcement. `role="alert"` would interrupt other narration — the user isn't in danger; the system just chose honesty over hallucination.

```tsx
<div
    data-testid="refusal-notice"
    data-reason={reason}
    role="status"
    aria-live="polite"
>
    <p>{body}</p>
    <p className="hint">Try refining your question or providing more context.</p>
</div>
```

## 4. Mirror across every controller

If `KbChatController` has the short-circuit, `MessageController` (conversation flow) MUST mirror it. Inconsistent refusal between API surfaces is a UX regression. Use the same config keys (`kb.refusal.*`) so the threshold is single-source-of-truth.

## 5. Per-reason i18n with generic fallback

The user-visible body is BE-localized. Use a hierarchy:
- `kb.refusal.{reason}` — per-reason copy (preferred)
- `kb.no_grounded_answer` — generic fallback when key missing

Helper:
```php
private function localizedRefusalMessage(string $reason): string {
    $key = "kb.refusal.{$reason}";
    $msg = __($key);
    return is_string($msg) && $msg !== $key
        ? $msg
        : (string) __('kb.no_grounded_answer');
}
```

The machine-readable `refusal_reason` tag NEVER localizes — it's an English identifier the dashboard rolls up across users with different locales.

## When to invoke

- New deterministic short-circuit before an external call
- New cost / quota / rate-limit guard
- New UX surface for "system declined to do X" (refusal, abstention, withhold)
- Reviewing a refusal flow that returns generic 500 / blocks the request

## References

- `app/Http/Controllers/Api/KbChatController.php::refusalResponse()` + `convertSentinelToRefusal()` (the canonical implementation)
- `app/Http/Controllers/Api/MessageController.php` (mirror)
- `tests/Feature/Api/KbChatRefusalTest.php` (9 cases proving no-LLM-call invariant)
- `tests/Feature/Api/KbChatSentinelTest.php` (7 cases including substring-not-refused boundary)
- `frontend/src/features/chat/RefusalNotice.tsx` (FE component with role=status)
- LESSONS.md L19, L20, L22
- CLAUDE.md R24, R26
