---
description: Use when writing a TanStack Query / Redux / Zustand onSuccess that merges an optimistic placeholder with the server-confirmed payload. Enforces idempotent merge by deduping BOTH the optimistic id AND the server response id.
---

# Optimistic Mutation Dedupe

When you write a mutation that pre-renders an optimistic row + replaces it with the server response, the merge MUST be idempotent. Same id appears AT MOST once after the merge — regardless of cache state.

## The bug pattern (don't ship this)

```ts
onSuccess: (assistantMessage, { conversationId }, context) => {
    qc.setQueryData<Message[]>(['messages', conversationId], (old) => {
        if (!old) return [assistantMessage];
        const optimisticId = context?.optimisticId;
        // BUG: only filters optimistic, not server-id
        const filtered = old.filter((m) => m.id !== optimisticId);
        return [...filtered, assistantMessage];
    });
    qc.invalidateQueries({ queryKey: ['messages', conversationId] });
},
```

If `old` already contains a row with the same id as `assistantMessage` (prior GET refetch race, fixture seed, fast-typist double-mutation), the merge produces `[same-id-A, same-id-A]` → React renders TWO components with identical id for ~100ms until reconciliation.

## The fix (idempotent merge)

```ts
onSuccess: (assistantMessage, { conversationId }, context) => {
    qc.setQueryData<Message[]>(['messages', conversationId], (old) => {
        if (!old) return [assistantMessage];
        const optimisticId = context?.optimisticId;
        const filtered = old.filter((m) => {
            if (optimisticId !== undefined && m.id === optimisticId) return false;
            if (m.id === assistantMessage.id) return false;  // dedupe by server id too
            return true;
        });
        return [...filtered, assistantMessage];
    });
    qc.invalidateQueries({ queryKey: ['messages', conversationId] });
},
```

## Test posture: strict-mode locators

If your Playwright spec uses `.first()` to tolerate duplicate elements, you're masking THIS bug. Strict-mode locators (the default) fail on duplicates — that's the contract. If a test starts seeing 2 elements with the same testid, fix the merge, NOT the test.

```ts
// CORRECT — strict-mode locator
await expect(page.getByTestId('chat-message-2001')).toBeVisible();

// WRONG — masks the dedupe bug
await expect(page.getByTestId('chat-message-2001').first()).toBeVisible();
```

## Direct repro test

```ts
test('GET stub returns the same id as POST → no duplicate render', async ({ page }) => {
    const sharedAssistant = { id: 99, role: 'assistant', content: 'X', metadata: null, ... };
    await page.route('**/conversations/*/messages', async (route) => {
        const method = route.request().method();
        if (method === 'GET') {
            return route.fulfill({ body: JSON.stringify([sharedAssistant]) });
        }
        return route.fulfill({ body: JSON.stringify(sharedAssistant) });
    });
    await page.goto('/app/chat');
    await composer(page).input.fill('Q');
    await composer(page).send.click();
    // Strict mode: passes only if dedupe is in place
    await expect(page.getByTestId(`chat-message-${sharedAssistant.id}`)).toHaveCount(1);
});
```

## When to invoke

- Writing a new TanStack Query mutation with `onSuccess` setQueryData merge
- Reviewing a Redux/Zustand reducer that merges optimistic + server payloads
- Debugging a Playwright spec that fails strict-mode locator assertions
- Auditing existing mutation code after seeing a "two same-id components" symptom

## References

- `frontend/src/features/chat/use-chat-mutation.ts::onSuccess` (canonical implementation)
- `frontend/e2e/chat-refusal.spec.ts` (spec running with strict-mode locators after the dedupe landed)
- LESSONS.md L28
- CLAUDE.md R25
