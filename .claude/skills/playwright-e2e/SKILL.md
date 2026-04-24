---
name: playwright-e2e
description: Author and maintain Playwright end-to-end scenarios for the AskMyDocs React SPA. Every UI-touching PR ships with at least one happy-path and one failure-path scenario covering the changed feature. Trigger when adding or modifying files under e2e/, when creating a new FE feature folder under frontend/src/features/, when updating a feature that already has a .spec.ts, or when a PR body mentions "UI coverage / E2E / playwright".
---

# Playwright E2E scenario authoring

## The three hard rules

1. **Every user-visible FE change ships one happy + one failure scenario**
   (R12). No exceptions; "it's just a small tweak" is not an exception.
2. **E2E exercises the real stack** — real Laravel, real DB (SQLite
   in CI), real Eloquent, real Sanctum cookies, real controllers
   (R13). `page.route(...)` is **only** for calls that leave the
   app boundary.
3. **Selectors and waits are semantic, not structural**. `getByTestId`,
   `getByRole` + accessible name, `toHaveAttribute('data-state', ...)`.
   Never CSS selectors, never `waitForTimeout`.

If the PR breaks any of the three, it does not ship.

## R13 — real-data rule in detail

Allowed stubs (`page.route(...)`):

| Target | Why it's OK |
|---|---|
| `**/api.openrouter.ai/**`, `**/api.openai.com/**`, `**/api.anthropic.com/**`, `**/generativelanguage.googleapis.com/**`, `**/regolo.ai/**` | AI providers — cost money, require prod creds, non-deterministic |
| `**/mailgun.net/**`, `**/sendgrid.com/**`, `**/api.mailersend.com/**` | Email senders |
| `**/s3.amazonaws.com/**`, `**/storage.googleapis.com/**`, `**/*.r2.cloudflarestorage.com/**` | Remote object storage (when `Storage::fake` isn't in play) |
| Any `**/api.stripe.com/**`, payment / billing rails | Money |
| OCR / speech / vision APIs | Money + non-determinism |
| **Controller paths that invoke one of the above internally** — e.g. `**/conversations/*/messages` (POST triggers the AI provider), `**/api/kb/promotion/promote` (dispatches ingestion that embeds via provider) | The external call is the reason |

Forbidden stubs — these are real-data territory:

| Target | Why it's a bug |
|---|---|
| `**/sanctum/csrf-cookie` | The CSRF round-trip is part of the user journey |
| `**/api/auth/me`, `**/api/auth/login`, `**/api/auth/logout` | Auth flows must run for real |
| `**/api/admin/metrics/**` (happy path) | Metrics aggregation is literally what the dashboard asserts |
| `**/api/admin/users`, `**/api/admin/roles` | CRUD is the feature |
| `**/api/kb/resolve-wikilink` (happy path) | Resolver talks only to local DB |
| `**/conversations` (GET, PATCH, DELETE) | No external call; local Eloquent only |
| Any `**/api/kb/*` that reads (search, show, tree) | Local DB + pgvector/FTS only |

**Exception:** stubbing an internal route to inject a failure mode
(`500`, `422`, timeout) is allowed because the real data path was
already covered by the happy-path scenario in the same file, and
the goal of the failure test is to prove the UI degrades. Mark
that test with a `/* R13: failure injection — real path tested in
"<happy test name>" */` comment so the intent is explicit.

### Pre-commit sanity check

The repo ships `scripts/verify-e2e-real-data.sh` (see below). It
greps `page.route(` under `frontend/e2e/` and flags any target
that doesn't match the allowed-external allowlist. Run it before
`git commit`:

```bash
bash scripts/verify-e2e-real-data.sh
```

CI runs the same script. A red exit is a merge block.

## Directory layout

```
frontend/
├── playwright.config.ts      ← webServer block boots php artisan serve (R13)
├── e2e/
│   ├── auth.setup.ts         ← one-time admin login → playwright/.auth/admin.json
│   ├── viewer.setup.ts       ← one-time viewer login → playwright/.auth/viewer.json
│   ├── fixtures.ts           ← seeded auto-fixture via /testing/reset + /testing/seed
│   ├── helpers.ts            ← testid getters, data-state waits
│   ├── auth.spec.ts          ← login / forgot / reset real flows
│   ├── chat.spec.ts
│   ├── admin-dashboard.spec.ts
│   ├── admin-users.spec.ts
│   ├── admin-kb.spec.ts
│   ├── admin-logs.spec.ts
│   ├── admin-maintenance.spec.ts
│   └── admin-insights.spec.ts
└── playwright/.auth/         ← gitignored
```

## `playwright.config.ts` — mandatory shape

```ts
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const skipWebServer = process.env.E2E_SKIP_WEBSERVER === '1';

export default defineConfig({
  testDir: './frontend/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    trace: 'on-first-retry',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  // R13: real backend. Playwright boots php artisan serve on the
  // same worktree and shuts it down after the suite. SKIP only
  // when an external server is already serving baseURL.
  webServer: skipWebServer ? undefined : {
    command: 'php artisan serve --host=127.0.0.1 --port=8000',
    url: baseURL,
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
    env: { APP_ENV: 'testing' },
    stdout: 'pipe',
    stderr: 'pipe',
  },
  projects: [
    { name: 'admin-setup',  testMatch: /auth\.setup\.ts/ },
    { name: 'viewer-setup', testMatch: /viewer\.setup\.ts/ },
    {
      name: 'chromium-admin',
      use: { ...devices['Desktop Chrome'], storageState: 'playwright/.auth/admin.json' },
      dependencies: ['admin-setup'],
      testIgnore: /.*\.setup\.ts|.*-viewer\.spec\.ts/,
    },
    {
      name: 'chromium-viewer',
      use: { ...devices['Desktop Chrome'], storageState: 'playwright/.auth/viewer.json' },
      dependencies: ['viewer-setup'],
      testMatch: /.*-viewer\.spec\.ts/,
    },
  ],
});
```

## `frontend/e2e/fixtures.ts` — the `seeded` auto-fixture

```ts
import { test as base, expect } from '@playwright/test';

// R13: every test resets the DB via /testing/reset and reseeds
// DemoSeeder before the scenario runs. No shared mutable state,
// no manual page.route() on /api/* to "fix" dirty data — fix the
// data instead.
export const test = base.extend<{ seeded: void }>({
  seeded: [async ({ request }, use) => {
    const reset = await request.post('/testing/reset');
    expect(reset.ok()).toBeTruthy();
    const seed = await request.post('/testing/seed', { data: { seeder: 'DemoSeeder' } });
    expect(seed.ok()).toBeTruthy();
    await use();
  }, { auto: true }],
});

export { expect };
```

`app/Http/Controllers/TestingController.php` is registered in
`routes/web.php` ONLY when `APP_ENV=testing` — never in prod.

## `frontend/e2e/helpers.ts` — semantic waits

```ts
import { expect, type Page, type Locator } from '@playwright/test';

export const testid = (page: Page, id: string): Locator => page.getByTestId(id);

// Canonical pattern for data-state waits. No MutationObserver
// plumbing, no custom evaluate — Playwright's expect polls
// attributes correctly.
export async function waitForReady(page: Page, testId: string, timeout = 15_000): Promise<void> {
  const el = page.getByTestId(testId);
  await el.waitFor({ state: 'visible', timeout });
  await expect(el).not.toHaveAttribute('data-state', 'loading', { timeout });
}
```

## `frontend/e2e/auth.setup.ts` — one-time login

```ts
import { test as setup, expect } from '@playwright/test';

const adminStorage = 'playwright/.auth/admin.json';

setup('authenticate as admin', async ({ page }) => {
  await page.goto('/login');
  await page.getByTestId('login-email').fill('admin@acme.io');
  await page.getByTestId('login-password').fill('secret123');
  await page.getByTestId('login-submit').click();
  await expect(page).toHaveURL(/\/app/);
  await page.context().storageState({ path: adminStorage });
});
```

`viewer.setup.ts` is the same with `viewer@acme.io` / `secret123`
and `playwright/.auth/viewer.json`. DemoSeeder must seed both.

## Scenario templates — copy & adapt

### Happy path (admin dashboard)

```ts
import { test, expect } from './fixtures';
import { testid, waitForReady } from './helpers';

test.describe('Admin dashboard', () => {
  test('renders KPIs + health + charts from real seeded data', async ({ page }) => {
    await page.goto('/app/admin');
    await waitForReady(page, 'dashboard-kpi-strip');
    // 6 KPI cards, real numbers from DemoSeeder
    for (const k of ['docs','chunks','chats','latency','cache','canonical']) {
      await expect(testid(page, `kpi-card-${k}`)).toHaveAttribute('data-state', 'ready');
    }
    // Health strip — at least 5 green concerns in a clean seed
    const health = testid(page, 'dashboard-health');
    await expect(health).toHaveAttribute('data-state', 'ok');
    // Charts are lazy-loaded — wait for the recharts SVG
    await waitForReady(page, 'dashboard-chat-volume');
    await expect(page.locator('[data-testid="dashboard-chat-volume"] svg')).toBeVisible();
  });
});
```

### Failure injection on an internal route (R13 exception)

```ts
test.describe('Admin dashboard — failure modes', () => {
  test('/* R13: failure injection — real path tested above */ metrics 500', async ({ page }) => {
    await page.route('**/api/admin/metrics/**', (route) => route.fulfill({ status: 500 }));
    await page.goto('/app/admin');
    await expect(testid(page, 'dashboard-error')).toBeVisible();
    await expect(testid(page, 'dashboard-error')).toContainText(/couldn.?t load/i);
  });
});
```

### Happy path (chat) — stub ONLY the AI provider boundary

```ts
test('user asks question and the assistant reply renders', async ({ page }) => {
  // Allowed (R13): POST /conversations/*/messages triggers the AI
  // provider. Stub the CONTROLLER endpoint because the external
  // call it makes is expensive + non-deterministic.
  await page.route('**/conversations/*/messages', async (route) => {
    if (route.request().method() !== 'POST') return route.fallback();
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        id: 1001, role: 'assistant',
        content: 'Answer with a [[remote-work-policy]] citation.',
        metadata: { provider: 'mock', model: 'mock', citations: [] },
        rating: null, created_at: new Date().toISOString(),
      }),
    });
  });
  await page.goto('/app/chat');
  await testid(page, 'chat-composer-input').fill('How does the stipend work?');
  await testid(page, 'chat-composer-send').click();
  await waitForReady(page, 'chat-thread', 45_000);
  await expect(page.locator('[data-testid^="chat-message-"][data-role="assistant"]').first()).toBeVisible();
});
```

### Failure path (chat) — validation, no stub needed

```ts
test('empty message surfaces a 422-style validation error', async ({ page }) => {
  await page.goto('/app/chat');
  await testid(page, 'chat-composer-send').click();
  const err = testid(page, 'message-error');
  await expect(err).toBeVisible();
  await expect(err).toContainText(/required/i);
});
```

### CRUD happy path (users admin) — no stubs at all

```ts
test('admin creates a user and the table shows the new row', async ({ page }) => {
  await page.goto('/app/admin/users');
  await testid(page, 'users-add').click();
  await testid(page, 'user-form-email').fill('new@acme.io');
  await testid(page, 'user-form-name').fill('New Hire');
  await testid(page, 'user-form-role').click();
  await page.getByRole('option', { name: 'Viewer' }).click();
  await testid(page, 'user-form-submit').click();
  await expect(testid(page, 'toast-success')).toBeVisible();
  const row = page.locator('[data-testid^="users-row-"]', { hasText: 'new@acme.io' });
  await expect(row).toBeVisible();
});
```

## Coverage matrix — every admin feature in PR6–PR10

| Feature | Happy | Failure injection | Empty/edge state |
|---|---|---|---|
| Login | valid creds → /app | bad creds → 422; 5× bad → 429 | — |
| Forgot password | real email → success | throttle → 429 | — |
| Chat | question → streamed answer (AI-provider stub ok) | provider 500 → inline error | 0 citations state |
| Dashboard | real KPIs + charts + health | `/api/admin/metrics/**` 500 → dashboard-error | zero chats → chart-empty; queue > 10 → health degraded |
| Users CRUD | create / edit / delete | duplicate email → 422; unauthorized → 403 | admin deleting self → dialog |
| Roles | assign / revoke | removing last super-admin → 409 | — |
| KB tree + editor | browse / open / edit / save / PDF | invalid frontmatter → 422; file lock → 409 | empty project → "no docs" empty state |
| Logs | tail + filter + export CSV | log file missing → empty-state | 0 results → zero-state |
| Maintenance | wizard preview / confirm / run | destructive without confirm → 400 | long-running → progress spinner |
| Insights | snapshot renders, action one-click | stale snapshot → regen pending banner | zero insights → onboarding tip |

## Pre-PR checklist

- [ ] `bash scripts/verify-e2e-real-data.sh` — 0 findings
- [ ] Every new feature has `e2e/<feature>.spec.ts` with ≥ 1 happy + ≥ 1 failure
- [ ] All selectors use `getByTestId` or `getByRole` + accessible name
- [ ] No `waitForTimeout`; use `waitForReady(page, testId)` or `toHaveAttribute('data-state', ...)`
- [ ] Uses authed storage state — no per-test login
- [ ] Failure paths that stub an internal route carry the `/* R13: failure injection */` marker comment
- [ ] `npm run e2e` green locally
- [ ] CI workflow updated if you added a new browser or a new setup project

## What a reviewer should reject

1. A test that runs fast and always passes — check for stubbed internal routes.
2. A scenario that calls `expect(locator).toBeVisible()` without any state assertion — likely watching nothing meaningful.
3. `await page.waitForTimeout(1000)` — always wrong.
4. Selectors like `.cursor-pointer > div:nth-child(2)` — will break on any CSS refactor.
5. A test file without a failure-path scenario — R12 violation.
6. `page.route('**/api/admin/*', ...)` on a happy path — R13 violation.

## Cross-reference

- DOM contract: `.claude/skills/frontend-testid-conventions/SKILL.md`
- Failure surfacing in components: R11 in `CLAUDE.md`
- Verification script: `scripts/verify-e2e-real-data.sh`
- Template archive: `.claude/skills/playwright-e2e-templates/` (copy the closest template and adapt)

---

## Extension: `waitForTimeout` is banned; `context.route` is covered

Distilled from PR16 live re-harvest.

### `page.waitForTimeout(N)` is banned (PR #28 admin-logs.spec)

Every `waitForTimeout` call is a latent flake: CI jitter makes 500ms
sometimes 100ms (too short, test races) and sometimes 3s (wastes CI
time). Wait on **observable state** instead.

```ts
// ❌
await page.click('[data-testid="logs-filter-apply"]');
await page.waitForTimeout(500);
await expect(testid(page, 'logs-table')).toContainText('2026-04-23');

// ✅ — wait on the response you just triggered
await Promise.all([
  page.waitForResponse(r =>
    r.url().includes('/api/admin/logs/chat') && r.request().method() === 'GET'
  ),
  page.click('[data-testid="logs-filter-apply"]'),
]);
await expect(testid(page, 'logs-table')).toContainText('2026-04-23');

// ✅ — wait on the state marker the component publishes
await page.click('[data-testid="logs-filter-apply"]');
await waitForReady(page, 'logs-table');
await expect(testid(page, 'logs-table')).toContainText('2026-04-23');
```

### `context.route` is also covered by R13

The `verify-e2e-real-data.sh` script originally only greped
`page.route(`. Playwright also intercepts via `browserContext.route`
or `page.context().route()` — the same semantic at a different
scope. PR #21 caught that gap; the script was patched. When
authoring a new scenario, treat `context.route` identically to
`page.route`:

```ts
// ❌ — bypasses the R13 gate without the marker
await page.context().route('**/api/admin/metrics/**', r => r.fulfill({ status: 500 }));

// ✅ — R13: failure injection marker + real path covered elsewhere
/* R13: failure injection — real path tested in "renders KPIs + health + charts" */
await page.context().route('**/api/admin/metrics/**', r => r.fulfill({ status: 500 }));
```

### Other PR16 learnings

- `remark-parse` must be listed as a direct devDependency in
  `package.json` — relying on transitive resolution breaks in fresh
  installs (PR #20).
- `Composer.test.tsx`-style TS file that references
  `React.ReactElement` without importing it fails `tsc -b` in CI —
  always `import type { ReactElement } from 'react'` (PR #20).
- `playwright.config.ts` MUST have a `webServer` block — without it,
  `baseURL` is unreachable in CI (PR #20).
- `Locator.evaluate` is `(fn, arg?)` — three args fails typecheck
  (PR #20).
- Happy path never stubs an internal chat endpoint unless the
  endpoint triggers an AI-provider call (R13 proxy pattern).

### Pre-PR checklist — extended

Add to the main checklist above:

- [ ] `rg 'waitForTimeout' frontend/e2e/` returns zero rows.
- [ ] Every `context.route(...)` targeting an internal path carries
      the `R13: failure injection` marker comment.
- [ ] `package.json` lists every module your tests import directly
      (no reliance on transitive resolution).
- [ ] `playwright.config.ts` has a `webServer` block that boots
      `php artisan serve` with `APP_ENV=testing`.
