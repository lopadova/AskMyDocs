---
name: playwright-e2e
description: Author and maintain Playwright end-to-end scenarios for the AskMyDocs React SPA. Every UI-touching PR ships with at least one happy-path and one failure-path scenario covering the changed feature. Trigger when adding or modifying files under e2e/, when creating a new FE feature folder under frontend/src/features/, when updating a feature that already has a .spec.ts, or when a PR body mentions "UI coverage / E2E / playwright".
---

# Playwright E2E scenario authoring

## Rule

Every PR that ships a user-visible frontend change must include at
least one Playwright scenario that:

1. Signs in (or is already authed via a reusable storage state fixture)
2. Navigates to the feature's route
3. Exercises the happy path with realistic data
4. Exercises at least one failure path (validation, 422, 429, network error)
5. Asserts on user-observable DOM, never on internals or timing

E2E runs in CI before merge; local `npm run e2e` is the pre-push gate.

## Why this exists

Unit tests prove components render; E2E prove the whole stack
(SPA ↔ Laravel ↔ DB ↔ AI providers) cooperates. The AskMyDocs admin
surface is large and stateful — login → dashboard → tree → editor →
PDF export → maintenance wizard — and the only practical way to regress-
test it is against a running app. User requested (Apr 23) that E2E be
professional-grade from PR5 onward.

## Setup (one-time per PR that bootstraps E2E)

```bash
cd frontend
npm install --save-dev @playwright/test
npx playwright install chromium firefox webkit --with-deps
```

Add to `frontend/package.json`:

```json
"scripts": {
  "e2e": "playwright test",
  "e2e:ui": "playwright test --ui",
  "e2e:headed": "playwright test --headed",
  "e2e:report": "playwright show-report"
}
```

### Directory layout

```
frontend/
├── playwright.config.ts
├── e2e/
│   ├── fixtures.ts              # shared fixtures (authedPage, admin DB seed)
│   ├── helpers.ts               # testid getters, API wait helpers
│   ├── auth.spec.ts
│   ├── chat.spec.ts
│   ├── admin-dashboard.spec.ts
│   ├── admin-users.spec.ts
│   ├── admin-kb.spec.ts
│   ├── admin-logs.spec.ts
│   ├── admin-maintenance.spec.ts
│   └── admin-insights.spec.ts
└── playwright/.auth/            # per-user storage state (gitignored)
```

### `playwright.config.ts` skeleton

```ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000',
    trace: 'on-first-retry',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /.*\.setup\.ts/ },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: 'playwright/.auth/admin.json' },
      dependencies: ['setup'],
    },
  ],
  webServer: [
    {
      command: 'php artisan serve --host=127.0.0.1 --port=8000',
      url: 'http://127.0.0.1:8000',
      reuseExistingServer: !process.env.CI,
      cwd: '..',
    },
    {
      command: 'npm run dev',
      url: 'http://127.0.0.1:5173',
      reuseExistingServer: !process.env.CI,
    },
  ],
});
```

## Conventions

### 1. Always select via `data-testid` or `getByRole` + name

```ts
await page.getByTestId('login-email').fill('admin@acme.io');
await page.getByTestId('login-submit').click();
await page.getByRole('heading', { name: 'Dashboard' }).waitFor();
```

Never `page.click('.btn-primary:nth-child(2)')` — that's drift bait.

### 2. Wait on data-state, not arbitrary timeouts

```ts
const usersTable = page.getByTestId('users-table');
await expect(usersTable).toHaveAttribute('data-state', 'ready');
```

### 3. Backend fixture resets

Use `@playwright/test` fixtures to seed DB state before scenarios:

```ts
export const test = base.extend<{ seeded: void }>({
  seeded: [async ({ request }, use) => {
    // Laravel exposes a `/testing/reset` endpoint in APP_ENV=testing
    await request.post('/testing/reset');
    await request.post('/testing/seed', { data: { seeder: 'DemoSeeder' } });
    await use();
  }, { auto: true }],
});
```

Ship the `TestingController` behind `APP_ENV=testing` guard and never
enable in production.

### 4. Auth storage state

Create a `auth.setup.ts` that signs in once and writes storageState:

```ts
import { test as setup } from '@playwright/test';

setup('authenticate as admin', async ({ page }) => {
  await page.goto('/login');
  await page.getByTestId('login-email').fill('admin@acme.io');
  await page.getByTestId('login-password').fill('secret123');
  await page.getByTestId('login-submit').click();
  await page.waitForURL('**/app');
  await page.context().storageState({ path: 'playwright/.auth/admin.json' });
});
```

All subsequent scenarios reuse this state — no re-login per test.

### 5. Scenario template

```ts
import { test, expect } from '@playwright/test';

test.describe('Admin KB — document editing', () => {
  test('author edits source and re-ingest triggers', async ({ page }) => {
    await page.goto('/app/admin/kb');
    await page.getByTestId('kb-tree').waitFor();
    await page.getByTestId('kb-tree-node-remote-work-policy.md').click();
    await page.getByTestId('kb-tab-source').click();
    await page.getByTestId('kb-editor').fill(/* new markdown */);
    await page.getByTestId('kb-editor-save').click();
    await expect(page.getByTestId('toast-success')).toBeVisible();
    await expect(page.getByTestId('kb-tab-history')).toContainText('re-ingested');
  });

  test('422 validation surfaces per-field errors', async ({ page }) => {
    await page.goto('/app/admin/kb');
    await page.getByTestId('kb-tree-node-remote-work-policy.md').click();
    await page.getByTestId('kb-editor').fill('invalid yaml front matter');
    await page.getByTestId('kb-editor-save').click();
    await expect(page.getByTestId('kb-editor-error')).toContainText('frontmatter');
  });
});
```

### 6. Network interception for failure injection

```ts
test('dashboard handles metrics 500', async ({ page }) => {
  await page.route('**/api/admin/metrics**', route => route.fulfill({ status: 500 }));
  await page.goto('/app/admin');
  await expect(page.getByTestId('dashboard-error')).toBeVisible();
});
```

### 7. CI integration

Add to `.github/workflows/tests.yml`:

```yaml
- name: Install Playwright
  run: cd frontend && npx playwright install --with-deps chromium
- name: Run E2E
  run: cd frontend && npm run e2e
  env:
    APP_ENV: testing
```

Expected test time: ~2-4 minutes for a full admin suite on one browser.

## Failure patterns that MUST be covered per feature

| Feature          | Happy path                          | Failure paths                                    |
|------------------|-------------------------------------|--------------------------------------------------|
| Login            | valid creds → /app                  | bad creds → 422; 5x bad → 429                    |
| Forgot password  | real email → success                | throttle → 429                                   |
| Chat             | question → streamed answer          | provider error → inline error; 0 citations state |
| Dashboard        | KPIs + sparklines render            | metrics endpoint 500 → error card                |
| Users CRUD       | create / edit / delete              | duplicate email → 422; unauthorized → 403        |
| KB tree + editor | browse / open / edit / save / PDF   | invalid frontmatter → 422; file lock → 409       |
| Logs             | tail + filter + export CSV          | log file missing → empty-state                    |
| Maintenance      | wizard preview / confirm / run      | destructive without confirm → 400                |
| Insights         | snapshot renders, action one-click  | stale snapshot → regen pending banner            |

## Quick checklist before PR

- [ ] Each new feature has `e2e/<feature>.spec.ts` with >= 1 happy + >= 1 failure
- [ ] All selectors use `getByTestId` or `getByRole` + accessible name
- [ ] No `waitForTimeout` calls (use `data-state` waits instead)
- [ ] Uses authed storage state — no per-test login
- [ ] Network failure injected via `page.route(...)` for at least one error case
- [ ] CI workflow updated if new dependency (browser) added
- [ ] `npm run e2e` green locally before push

See also `.claude/skills/frontend-testid-conventions/SKILL.md` for the
DOM contract consumed by these scenarios.
