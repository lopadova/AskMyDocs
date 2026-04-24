---
name: playwright-e2e-templates
description: Copy-paste templates for writing Playwright E2E scenarios in AskMyDocs. Use when starting a new `frontend/e2e/*.spec.ts` file, setting up a new setup-project (admin/viewer/editor), or when you need a proven pattern for data-state waits, failure injection, CRUD flows, or wizard flows. Templates are aligned with R11 (testids) / R12 (coverage) / R13 (real data, external-only stubs).
---

# E2E scenario templates

All templates below follow the three hard rules: real backend, semantic
selectors, one happy + one failure per feature. Copy the closest
match, adjust testids and data-testid waits, run `bash
scripts/verify-e2e-real-data.sh` before committing.

## 1. Skeleton — new feature spec file

```ts
// frontend/e2e/<feature>.spec.ts
import { test, expect } from './fixtures';
import { testid, waitForReady } from './helpers';

test.describe('<feature>', () => {
  test('happy path — <what the user does>', async ({ page }) => {
    await page.goto('/app/<route>');
    await waitForReady(page, '<feature>-view');

    // 1. exercise the real action
    await testid(page, '<feature>-primary-action').click();

    // 2. assert observable outcome
    await expect(testid(page, 'toast-success')).toBeVisible();
    await expect(testid(page, '<feature>-view')).toHaveAttribute('data-state', 'ready');
  });

  test('failure path — <specific failure>', async ({ page }) => {
    await page.goto('/app/<route>');
    // No stub — drive the failure through the real form / permission.
    await testid(page, '<feature>-primary-action').click();
    await expect(testid(page, '<field>-error')).toContainText(/<expected message>/i);
  });
});
```

## 2. CRUD list + drawer form

```ts
test('admin creates a user and the table shows the row', async ({ page }) => {
  await page.goto('/app/admin/users');
  await waitForReady(page, 'users-table');

  await testid(page, 'users-add').click();
  await testid(page, 'user-form-email').fill('new@acme.io');
  await testid(page, 'user-form-name').fill('New Hire');
  await testid(page, 'user-form-role-picker').click();
  await page.getByRole('option', { name: 'Viewer' }).click();
  await testid(page, 'user-form-submit').click();

  await expect(testid(page, 'toast-success')).toBeVisible();
  const row = page.locator('[data-testid^="users-row-"]', { hasText: 'new@acme.io' });
  await expect(row).toBeVisible();
});

test('duplicate email surfaces 422 per-field error', async ({ page }) => {
  await page.goto('/app/admin/users');
  await testid(page, 'users-add').click();
  await testid(page, 'user-form-email').fill('admin@acme.io'); // already seeded
  await testid(page, 'user-form-name').fill('Dup');
  await testid(page, 'user-form-submit').click();
  await expect(testid(page, 'user-form-email-error')).toContainText(/already/i);
});
```

## 3. Wizard (maintenance panel — artisan runner)

```ts
test('admin runs kb:rebuild-graph through the maintenance wizard', async ({ page }) => {
  await page.goto('/app/admin/maintenance');
  await waitForReady(page, 'maintenance-list');

  await testid(page, 'maintenance-card-kb-rebuild-graph').click();

  // Step 1 — Preview (dry run)
  await testid(page, 'wizard-step-preview-run').click();
  await expect(testid(page, 'wizard-preview-output')).toBeVisible();

  // Step 2 — Confirm (checkbox + type-to-confirm for destructive)
  await testid(page, 'wizard-confirm-checkbox').check();
  await testid(page, 'wizard-confirm-continue').click();

  // Step 3 — Run
  await testid(page, 'wizard-run').click();
  await expect(testid(page, 'wizard-result')).toHaveAttribute('data-state', 'ready');
  await expect(testid(page, 'wizard-result')).toContainText(/exit 0/);
});

/* R13: failure injection — real path tested in "runs kb:rebuild-graph". */
test('destructive command without confirm token is rejected', async ({ page }) => {
  await page.goto('/app/admin/maintenance');
  await testid(page, 'maintenance-card-kb-prune-deleted').click();
  await testid(page, 'wizard-run').click(); // skip confirm step
  await expect(testid(page, 'wizard-error')).toContainText(/confirm/i);
});
```

## 4. Markdown editor (KB viewer/editor)

```ts
test('admin edits a doc and save triggers re-ingest', async ({ page }) => {
  await page.goto('/app/admin/kb');
  await waitForReady(page, 'kb-tree');

  await testid(page, 'kb-tree-node-policies/remote-work-policy').click();
  await testid(page, 'kb-tab-source').click();

  const editor = testid(page, 'kb-editor-cm');
  await editor.click();
  await page.keyboard.press('End');
  await page.keyboard.type('\n\nUpdated by E2E.');

  await testid(page, 'kb-editor-save').click();
  await expect(testid(page, 'toast-success')).toBeVisible();

  await testid(page, 'kb-tab-history').click();
  await expect(testid(page, 'kb-history-latest')).toContainText(/re-ingested/i);
});

test('invalid frontmatter rejects save with 422', async ({ page }) => {
  await page.goto('/app/admin/kb');
  await testid(page, 'kb-tree-node-policies/remote-work-policy').click();
  await testid(page, 'kb-tab-source').click();
  await testid(page, 'kb-editor-cm').click();
  await page.keyboard.press('Control+Home');
  await page.keyboard.type('---\nstatus: NOT_A_VALID_STATUS\n---\n');
  await testid(page, 'kb-editor-save').click();
  await expect(testid(page, 'kb-editor-error')).toContainText(/status/i);
});
```

## 5. Charts (lazy-loaded)

```ts
test('chat volume chart renders real data', async ({ page }) => {
  await page.goto('/app/admin');
  await waitForReady(page, 'dashboard-chat-volume');
  // recharts is React.lazy — wait on the SVG, not the wrapper
  await expect(page.locator('[data-testid="dashboard-chat-volume"] svg')).toBeVisible();
});

test('empty-state chart (zero chats) shows the stub SVG', async ({ page, request }) => {
  // Reset the DB to a state without any chat_logs — seeder with an
  // empty variant, not a stub. Real data all the way.
  await request.post('/testing/seed', { data: { seeder: 'EmptyKbSeeder' } });
  await page.goto('/app/admin');
  await expect(testid(page, 'dashboard-chat-volume-empty')).toBeVisible();
});
```

## 6. RBAC forbidden (viewer storage state)

```ts
// frontend/e2e/admin-dashboard-viewer.spec.ts
// Matched by the `chromium-viewer` project, auth state is viewer.json.
import { test, expect } from './fixtures';
import { testid } from './helpers';

test('viewer visiting /app/admin sees the forbidden surface', async ({ page }) => {
  await page.goto('/app/admin');
  await expect(testid(page, 'admin-forbidden')).toBeVisible();
});
```

## 7. Failure injection catalogue (allowed shapes)

```ts
// Provider 500 — chat
await page.route('**/conversations/*/messages', (r) =>
  r.request().method() === 'POST'
    ? r.fulfill({ status: 500, body: '{"message":"provider failure"}' })
    : r.fallback(),
);

// Provider timeout — embeddings (ingest path)
await page.route('**/api/kb/ingest', (r) => r.abort('timedout'));

// Email provider 4xx — forgot password
await page.route('**/api.mailgun.net/**', (r) => r.fulfill({ status: 429 }));

// Internal endpoint 500 — failure-only, happy case covered elsewhere
// Mark with the comment so reviewers see the justification.
/* R13: failure injection — real path tested in "<name>". */
await page.route('**/api/admin/metrics/**', (r) => r.fulfill({ status: 500 }));
```

## 8. Storage-state setup templates

```ts
// frontend/e2e/viewer.setup.ts
import { test as setup, expect } from '@playwright/test';
const path = 'playwright/.auth/viewer.json';
setup('authenticate as viewer', async ({ page }) => {
  await page.goto('/login');
  await page.getByTestId('login-email').fill('viewer@acme.io');
  await page.getByTestId('login-password').fill('secret123');
  await page.getByTestId('login-submit').click();
  await expect(page).toHaveURL(/\/app/);
  await page.context().storageState({ path });
});
```

## 9. Do / Don't matrix

| Do | Don't |
|---|---|
| `getByTestId('users-row-42')` | `.user-row:nth-child(3)` |
| `toHaveAttribute('data-state', 'ready')` | `waitForTimeout(1500)` |
| Reset DB via `/testing/seed` per fixture | `page.route('**/api/admin/users', …)` on happy path |
| Stub `**/api.openrouter.ai/**` in ingest test | Stub `**/api/kb/ingest` on happy path |
| Comment failure injections with `R13: failure injection` | Silently stub an internal route and ship |
| Use `expect(...).toBeVisible({ timeout })` | Hand-roll `new Promise(resolve => ...)` with MutationObserver |

## 10. Running the suite

```bash
# local (webServer boots php artisan serve automatically)
npm run e2e

# UI mode for authoring
npm run e2e:ui

# headed mode to watch the browser
npm run e2e:headed

# open the HTML report after a failure
npm run e2e:report

# ignore the webServer block (you already have php artisan serve up)
E2E_SKIP_WEBSERVER=1 npm run e2e
```

## 11. Before opening the PR

1. `bash scripts/verify-e2e-real-data.sh` — no findings.
2. `npm run e2e` — green locally.
3. Grep your new spec for `waitForTimeout`, `page.route('**/api/` — should be zero matches (or all marked with `R13: failure injection` comments).
4. Confirm each touched feature has at least one test under `frontend/e2e/` that names it.
