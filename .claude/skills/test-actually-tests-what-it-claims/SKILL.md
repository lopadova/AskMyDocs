---
name: test-actually-tests-what-it-claims
description: A test body must exercise the behaviour its name promises. Ordering tests use strictly-monotonic fixtures with strict comparisons. Global-state mutations (env, DI, window.location, Date.now) are restored in afterEach. Failure-path tests actually fire the failure. Trigger when editing any *.test.tsx / tests/**/*.php / *.spec.ts file, or when adding a new test — especially tests with names starting with "enables", "disables", "rejects", "returns empty", "orders", "handles failure".
---

# Test actually tests what it claims

## Rule

Before you close `it(...)` or `public function testX`:

1. **Name ↔ body match** — the assertion on the last line must be the
   direct consequence of the action on the first line. No test named
   "enables Save after edit" should assert that Save is disabled.
2. **Ordering / sorting** tests use strictly-monotonic fixtures AND
   strict comparisons (`>`, `<`, not `>=` / `<=`). Under reversed
   response order the test MUST fail.
3. **Global-state mutations** (env vars, DI container bindings,
   `window.location`, `Date.now`, `fetch` monkeypatches) are captured
   in `beforeEach` and restored in `afterEach`. PHPUnit:
   `$this->app->make(...)` / `Env::getRepository()->clear(...)` then
   re-bind in `tearDown`.
4. **Failure-path** tests actually provoke the failure — click Save,
   POST invalid body, stub the downstream to 500. "Render the
   component and assert the error UI is absent" is a no-op.
5. **Empty-state** tests inspect for the empty-state testid, not for
   `data-state="ready"` with zero children.

## Symptoms in a review diff

- Test title: `it('enables Save after edit', ...)`. Body: `render(); expect(save).toBeDisabled()`. **No edit simulated.**
- Test title: `it('history is ordered desc by created_at', ...)`. Assertion: `expect($last).toBeGreaterThanOrEqual($first)` — passes under either order.
- `beforeEach(() => { Object.defineProperty(window, 'location', ...) })` with no matching `afterEach` restoring the original.
- `app()->detectEnvironment(fn() => 'production')` in a PHPUnit test without a `tearDown` restoring the env.
- `it('422 error surfaces inline', ...)` with `stub(api).toReturn(422)` but no button click that triggers the request.
- `it('renders empty-state', ...)` asserting `expect(container).toHaveAttribute('data-state', 'ready')` — ready ≠ empty.

## How to detect in-code

```bash
# Weak ordering assertions
rg -n "toBeGreaterThanOrEqual|toBeLessThanOrEqual" frontend/src/ tests/
rg -n "greaterThanOrEqualTo|lessThanOrEqualTo" tests/

# window.location override without restore
rg -n "Object\.defineProperty\(window,\s*'location'" frontend/src/ -A 5

# detectEnvironment mutation without tearDown
rg -n "app\(\)->detectEnvironment\(" tests/

# Tests whose body looks shorter than their name promises
# (heuristic — human eyeball)
rg -n "^\s*it\(" frontend/src/ | head -50
```

## Fix templates

### Enabled-after-edit test must simulate the edit (PR #26 SourceTab)

```tsx
// ❌
test('enables Save + Cancel after an edit', async () => {
  render(<SourceTab documentId={1} />);
  await waitFor(() => screen.getByTestId('source-editor'));
  expect(screen.getByTestId('source-save')).toBeDisabled();  // never edited!
});

// ✅
test('enables Save + Cancel after an edit', async () => {
  const { user } = renderWithProviders(<SourceTab documentId={1} />);
  await waitFor(() => screen.getByTestId('source-editor'));

  const view = getEditorViewFromTestDom();
  act(() => {
    view.dispatch({
      changes: { from: 0, insert: '# new heading\n' },
    });
  });

  expect(screen.getByTestId('source-save')).toBeEnabled();
  expect(screen.getByTestId('source-cancel')).toBeEnabled();
});
```

### Ordering test uses strict comparison + monotonic fixture (PR #25)

```php
// ❌ Passes under asc OR desc
$resp = $this->getJson('/api/admin/kb/docs/1/history');
$events = $resp->json('data');
$this->assertTrue($events[count($events) - 1]['created_at'] >= $events[0]['created_at']);

// ✅
// Fixture: 3 audits created at t, t+60s, t+120s
$resp = $this->getJson('/api/admin/kb/docs/1/history');
$events = $resp->json('data');
$first = Carbon::parse($events[0]['created_at']);
$last  = Carbon::parse($events[count($events) - 1]['created_at']);
$this->assertTrue($first->gt($last), 'history must be desc by created_at');
$this->assertEquals(3, count($events));
```

### Restore window.location in afterEach (PR #30)

```ts
// ❌
beforeEach(() => {
  Object.defineProperty(window, 'location', {
    value: { assign: vi.fn() },
  });
});

// ✅
const originalLocation = window.location;
beforeEach(() => {
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { ...originalLocation, assign: vi.fn() },
  });
});
afterEach(() => {
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: originalLocation,
  });
});
```

### Restore detectEnvironment (PR #20)

```php
// ❌
public function test_production_denies_testing_endpoint(): void
{
    $this->app->detectEnvironment(fn() => 'production');
    // ...
}

// ✅
protected function tearDown(): void
{
    $this->app->detectEnvironment(fn() => 'testing');
    parent::tearDown();
}

public function test_production_denies_testing_endpoint(): void
{
    $this->app->detectEnvironment(fn() => 'production');
    // ...
}
```

### Failure path clicks the button (PR #26 SourceTab 422)

```tsx
// ❌
test('422 frontmatter error surfaces in the UI', async () => {
  server.use(rest.patch('/api/admin/kb/docs/:id/raw', (req, res, ctx) =>
    res(ctx.status(422), ctx.json({ errors: { content: ['bad frontmatter'] }}))));
  render(<SourceTab documentId={1} />);
  expect(screen.queryByTestId('source-error')).toBeNull();  // trivially true
});

// ✅
test('422 frontmatter error surfaces in the UI', async () => {
  server.use(rest.patch('/api/admin/kb/docs/:id/raw', (req, res, ctx) =>
    res(ctx.status(422), ctx.json({ errors: { content: ['bad frontmatter'] }}))));

  const { user } = renderWithProviders(<SourceTab documentId={1} />);
  await waitFor(() => screen.getByTestId('source-editor'));

  const view = getEditorViewFromTestDom();
  act(() => view.dispatch({ changes: { from: 0, insert: '---\nbroken\n' } }));

  await user.click(screen.getByTestId('source-save'));
  const err = await screen.findByTestId('source-error');
  expect(err).toHaveTextContent(/bad frontmatter/);
});
```

### Empty-state inspects for empty-state (PR #27)

```ts
// ❌
test('raw doc surfaces empty graph', async ({ page }) => {
  await page.goto('/app/admin/kb/doc/99');
  await expect(page.getByTestId('kb-graph')).toHaveAttribute('data-state', 'ready');
  await expect(page.getByTestId('kb-graph-node-center')).toBeVisible();
});

// ✅
test('raw doc surfaces kb-graph-empty', async ({ page }) => {
  await page.goto('/app/admin/kb/doc/99');
  await expect(page.getByTestId('kb-graph')).toHaveAttribute('data-state', 'empty');
  await expect(page.getByTestId('kb-graph-empty')).toBeVisible();
  await expect(page.getByTestId('kb-graph-node-center')).toHaveCount(0);
});
```

## Related rules

- R11 — `data-state="empty"` is a first-class state, not a fallback to
  `"ready"`.
- R12 — every UI PR ships a failure-path scenario. "Failure path" is
  not "test the absence of failure UI".
- R13 — real-data E2E; don't stub the internal happy path.
- R16 is R11 / R12 / R13's quality gate: even if you have a failure
  spec, if it doesn't fire the failure the gate doesn't actually gate.

## Enforcement

- `copilot-review-anticipator` sub-agent reads every test file in a
  diff and flags name↔body mismatches via grep + semantic inspection.
- No dedicated CI script — false-positive rate too high. The manual
  reviewer should scan every `it('enables')` / `it('orders')` /
  `it('handles failure')` for an action in the body that would produce
  the state the name promises.
- Vitest + PHPUnit both surface state leak as flaky cross-suite
  behaviour — when a suite order change makes a test fail, R16 was
  the problem.

## Counter-example

```tsx
// ❌ Name says "enables", body never edits, body never checks enabled.
test('enables Save + Cancel after an edit', async () => {
  render(<SourceTab documentId={1} />);
  await waitFor(() => screen.getByTestId('source-editor'));
  expect(screen.getByTestId('source-save')).toBeDisabled();
});

// ❌ Name says "history desc", assertion passes under asc too.
$this->assertTrue($events[last]['created_at'] >= $events[0]['created_at']);

// ❌ Name says "empty", assertion says "ready with one center node".
await expect(locator('[data-testid=kb-graph]')).toHaveAttribute('data-state', 'ready');
```
