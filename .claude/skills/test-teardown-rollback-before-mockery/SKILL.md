---
name: test-teardown-rollback-before-mockery
description: Order test tearDown so the RefreshDatabase rollback runs BEFORE Mockery::close(), so an unmet mock expectation can never skip the rollback and cascade an "active transaction" suite-wide failure (R41).
---

# Test teardown: roll back the DB before Mockery::close() (R41)

## The bug

```php
protected function tearDown(): void
{
    Mockery::close();      // ❌ throws here on an unmet ->once() expectation
    parent::tearDown();    // ❌ never reached → RefreshDatabase rollback SKIPPED
}
```

When a mocked expectation (`->once()`, `->times(n)`) is **unmet** — e.g. the
code-under-test took a branch that skipped the mocked call — `Mockery::close()`
throws. Placed before `parent::tearDown()`, that throw aborts teardown, so the
`RefreshDatabase` transaction is **never rolled back**. The leaked open
transaction makes the **next** test fail with:

```
PDOException: There is already an active transaction
```

and the risky warning:

```
Test ... did not remove its own error/exception handlers
```

In random order, one real failure becomes a **suite-wide cascade** that masks
the true culprit and reads as non-deterministic flake. This is graded on
**blast radius, not frequency**: one fragile teardown poisons everything that
runs after it.

## The fix

```php
protected function tearDown(): void
{
    parent::tearDown();    // ✅ rollback ALWAYS happens first
    Mockery::close();      // ✅ safe to throw now; DB is already clean
}
```

Even better — **drop the manual `Mockery::close()` entirely**. Laravel/Testbench
already close Mockery inside the framework `tearDown()` (after the rollback,
wrapped in try/catch), so the manual call is redundant:

```php
// No custom tearDown needed at all — the framework handles both.
```

## Prevent the trigger too

Add a `TenantContext` (or any request-scoped singleton) reset to the **base**
`TestCase::setUp()` so a tenant-switching test cannot leak state into a sibling
and cause the unmet expectation in the first place:

```php
protected function setUp(): void
{
    parent::setUp();
    if ($this->app !== null && $this->app->bound(\App\Support\TenantContext::class)) {
        $this->app->make(\App\Support\TenantContext::class)->reset();
    }
}
```

## Checklist

- [ ] Every custom `tearDown()` calls `parent::tearDown()` FIRST.
- [ ] Any `Mockery::close()` / throwing cleanup runs AFTER `parent::tearDown()`,
      or is removed (framework closes Mockery safely).
- [ ] Base `TestCase::setUp()` resets request-scoped singletons.
- [ ] "active transaction" / "did not remove its own handlers" → fix the
      teardown, do NOT just re-run CI (R22 artefact-first still applies, but
      this signature has a known root cause).

## Grep to find offenders

```bash
# Risky order: Mockery::close() before parent::tearDown() in the SAME method,
# even with other lines (e.g. a TenantContext reset) BETWEEN them. The `[^}]*?`
# keeps the match inside one method body so it can't span across `}` into the
# next method.
grep -rlzoP "Mockery::close\(\);(?:[^}]*?)parent::tearDown\(\);" tests/
```

A naive `Mockery::close\(\);\s*\n\s*parent::tearDown\(\);` only catches the exact
two-line adjacency and MISSES offenders with a line in between — e.g.
`Mockery::close(); $this->app->make(TenantContext::class)->reset(); parent::tearDown();`.
Always use the body-spanning pattern above. (Note: when a reset line legitimately
needs `$this->app`, keep it BEFORE `parent::tearDown()` — only `Mockery::close()`
moves after.)

v8.8/W1 swept 35 adjacent files plus 3 non-adjacent ones (Copilot caught the
latter — the naive grep had missed them).
