import { expect, type Page } from '@playwright/test';
import { test } from './fixtures';
import { chooseSelectOption, openSelect } from './helpers';

/*
 * Admin "Path contexts" scenarios — admin-authored descriptions bound to a
 * (project_key, path_prefix) pair.
 *
 * R13: the happy path and the validation path run against the REAL Laravel
 * app and DemoSeeder data — ZERO route stubs. The one `page.route` below is
 * a deliberately-marked failure injection (500 from PUT), and the real-data
 * round-trip in the first test already covers the same flow.
 *
 * Assumes the Playwright env runs with KB_PATH_CONTEXTS_ENABLED=true (the
 * feature flag is OFF by default; with it OFF the page renders the
 * `unavailable` panel, which is covered by the Vitest suite).
 *
 * The project is chosen from the select's REAL options (derived from the
 * projects API) — no hard-coded project key here either (R18). The control
 * is the shared shadcn Select, so options are picked by opening the real
 * portalled menu (never by mutating the hidden native select).
 *
 * Locale: the admin's interface language depends on the seeded user, so
 * assertions rely on testids / attributes, never on translated copy. The
 * only text checks are locale-neutral (counter, server data, a server error
 * message, or a regex accepting both the EN and IT validation message).
 */

test.describe.configure({ timeout: 90_000 });

const LIST_URL = '/api/admin/kb/path-contexts';

async function openPage(page: Page) {
    await page.goto('/app/admin/path-contexts');
    const root = page.getByTestId('admin-path-contexts-page');
    await expect(root).toBeVisible({ timeout: 15_000 });
    // Settled = anything but loading/idle; the flag must be ON in this env.
    await expect(root).toHaveAttribute('data-state', /^(empty|ready)$/, { timeout: 15_000 });
    return root;
}

/** Selects the first real project option (index 0 is "Select a project…") and returns its key. */
async function pickFirstProject(page: Page): Promise<string> {
    const trigger = page.getByTestId('path-context-project');
    const menu = await openSelect(trigger);
    const firstProject = menu.getByRole('option').nth(1);
    await expect(firstProject).toBeVisible({ timeout: 10_000 });
    const projectKey = await firstProject.getAttribute('data-value');
    if (!projectKey) throw new Error('No project option is available in the path-context form.');
    await chooseSelectOption(trigger, projectKey);
    await expect(trigger).toHaveAttribute('data-value', projectKey);
    return projectKey;
}

test.describe('Admin Path contexts', () => {
    test('full add → edit → delete round-trip against the real backend', async ({ page }) => {
        await openPage(page);

        // ─── CREATE ────────────────────────────────────────────────
        await page.getByTestId('admin-path-contexts-add').click();
        await expect(page.getByTestId('path-context-form')).toHaveAttribute('data-mode', 'create');
        const projectKey = await pickFirstProject(page);
        await page.getByTestId('path-context-prefix').fill('e2e-policies/');
        await page.getByTestId('path-context-description').fill('Current policies approved by the DPO.');
        await expect(page.getByTestId('path-context-description-count')).toHaveText('37 / 500');

        const createPut = page.waitForResponse(
            (r) => r.url().endsWith(LIST_URL) && r.request().method() === 'PUT',
            { timeout: 15_000 },
        );
        await page.getByTestId('path-context-save').click();
        const createResp = await createPut;
        if (!createResp.ok()) {
            throw new Error(`PUT ${LIST_URL} returned non-OK: ${createResp.status()} ${await createResp.text()}`);
        }
        const created = await createResp.json();
        const id = created.data.id as number;

        const row = page.getByTestId(`admin-path-context-row-${id}`);
        await expect(row).toBeVisible({ timeout: 10_000 });
        await expect(row).toContainText(projectKey);
        // The server normalises the prefix through KbPath (R1): the trailing
        // slash typed in the form is not stored, so the row shows `e2e-policies`.
        await expect(row).toHaveAttribute('data-path-prefix', 'e2e-policies');
        await expect(row).toContainText('e2e-policies');
        await expect(row).toContainText('Current policies approved by the DPO.');
        await expect(page.getByTestId('path-context-form')).toHaveCount(0);

        // ─── EDIT (description only: project + prefix are the identity) ──
        await page.getByTestId(`admin-path-context-row-${id}-edit`).click();
        await expect(page.getByTestId('path-context-form')).toHaveAttribute('data-mode', 'edit');
        await expect(page.getByTestId('path-context-project')).toBeDisabled();
        await expect(page.getByTestId('path-context-prefix')).toHaveAttribute('readonly', '');
        await page.getByTestId('path-context-description').fill('Updated description for the e2e context.');

        const editPut = page.waitForResponse(
            (r) => r.url().endsWith(LIST_URL) && r.request().method() === 'PUT',
            { timeout: 15_000 },
        );
        await page.getByTestId('path-context-save').click();
        const editResp = await editPut;
        if (!editResp.ok()) {
            throw new Error(`PUT ${LIST_URL} (edit) returned non-OK: ${editResp.status()} ${await editResp.text()}`);
        }
        // Upsert on (project_key, path_prefix): same row, new description.
        expect((await editResp.json()).data.id).toBe(id);
        await expect(row).toContainText('Updated description for the e2e context.', { timeout: 10_000 });

        // ─── DELETE (explicit confirm step) ────────────────────────
        await page.getByTestId(`admin-path-context-row-${id}-delete`).click();
        const confirm = page.getByTestId(`admin-path-context-row-${id}-delete-confirm`);
        await expect(confirm).toBeVisible();
        await expect(row).toBeVisible();

        const deleteCall = page.waitForResponse(
            (r) => r.url().endsWith(`${LIST_URL}/${id}`) && r.request().method() === 'DELETE',
            { timeout: 15_000 },
        );
        await confirm.click();
        const deleteResp = await deleteCall;
        if (!deleteResp.ok()) {
            throw new Error(`DELETE ${LIST_URL}/${id} returned non-OK: ${deleteResp.status()} ${await deleteResp.text()}`);
        }
        await expect(row).toHaveCount(0, { timeout: 10_000 });
    });

    test('submitting an empty form shows a field error next to every input', async ({ page }) => {
        await openPage(page);
        await page.getByTestId('admin-path-contexts-add').click();
        await page.getByTestId('path-context-save').click();

        await expect(page.getByTestId('path-context-project-error')).toBeVisible();
        await expect(page.getByTestId('path-context-prefix-error')).toBeVisible();
        await expect(page.getByTestId('path-context-description-error')).toBeVisible();
        await expect(page.getByTestId('path-context-prefix')).toHaveAttribute('aria-invalid', 'true');
        // The form stays open for correction.
        await expect(page.getByTestId('path-context-form')).toBeVisible();

        // A rule only the server-mirrored validator knows about: leading slash.
        await pickFirstProject(page);
        await page.getByTestId('path-context-prefix').fill('/absolute/');
        await page.getByTestId('path-context-description').fill('x');
        await page.getByTestId('path-context-save').click();
        // EN "…must not start with a slash." / IT "…non deve iniziare con una barra (/)."
        await expect(page.getByTestId('path-context-prefix-error')).toContainText(/slash|barra/);
        await expect(page.getByTestId('path-context-project-error')).toHaveCount(0);
        await expect(page.getByTestId('path-context-description-error')).toHaveCount(0);
    });

    // R13: failure injection — the happy-path test above already covers the
    // real-data PUT flow; this one forces a 500 so the FE error branch (R14)
    // is exercised deterministically.
    test('a 500 from PUT surfaces an error in the form without crashing the page', async ({ page }) => {
        await openPage(page);

        // R13: failure injection
        await page.route('**/api/admin/kb/path-contexts', (route) => {
            if (route.request().method() !== 'PUT') {
                return route.continue();
            }
            return route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Server exploded.' }),
            });
        });

        await page.getByTestId('admin-path-contexts-add').click();
        await pickFirstProject(page);
        await page.getByTestId('path-context-prefix').fill('e2e-failure/');
        await page.getByTestId('path-context-description').fill('This save is rejected.');
        await page.getByTestId('path-context-save').click();

        await expect(page.getByTestId('path-context-form-error')).toContainText('Server exploded.', { timeout: 10_000 });
        // Form still open, page still alive and in a settled state.
        await expect(page.getByTestId('path-context-form')).toBeVisible();
        await expect(page.getByTestId('admin-path-contexts-page')).toHaveAttribute('data-state', /^(empty|ready)$/);
    });
});
