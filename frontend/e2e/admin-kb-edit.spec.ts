import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * PR10 — Phase G3. Admin KB source editor scenarios.
 *
 * Runs against the REAL backend seeded by DemoSeeder. The seeder writes
 * canonical markdown with a `---` frontmatter fence to the KB disk, so
 * switching to the Source tab pulls the current bytes into CodeMirror
 * and any subsequent save round-trips through:
 *   PATCH /api/admin/kb/documents/{id}/raw
 *     -> Storage::put + KbCanonicalAudit + IngestDocumentJob dispatch
 *   (then) invalidateQueries on raw/show/history/tree
 *
 * R13 compliance: the failure-injection scenario below is the ONLY
 * place we use request interception against an internal route, and
 * it carries the required marker comment on the preceding lines.
 */

test.describe('Admin KB Source Editor', () => {
    test('happy — edit, save, toast and new history row', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const node = page.getByTestId('kb-tree-node-policies/remote-work-policy.md');
        await expect(node).toBeVisible({ timeout: 10_000 });
        await node.click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });

        // Switch to the Source tab. The editor mounts once raw data lands.
        await page.getByTestId('kb-tab-source').click();
        await expect(page.getByTestId('kb-source')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // CodeMirror host is present. CM renders a .cm-content contenteditable
        // inside it; we click into it and type at the end of the buffer.
        const cm = page.getByTestId('kb-editor-cm');
        await expect(cm).toBeVisible();
        const content = cm.locator('.cm-content');
        await content.click();
        // Move cursor to end of document, then append a marker line.
        await page.keyboard.press('Control+End');
        await page.keyboard.type('\n\n<!-- edited by e2e -->\n');

        // Save is enabled once the buffer diverges from the saved baseline.
        const save = page.getByTestId('kb-editor-save');
        await expect(save).toBeEnabled({ timeout: 10_000 });
        // Wait for the PATCH /raw response BEFORE asserting on the
        // toast — without this the assertion can race the response
        // and the toast (which auto-dismisses after 5s) may already
        // be gone by the time Playwright catches up if the response
        // is unusually fast.
        const saveResponse = page.waitForResponse(
            (resp) => /\/api\/admin\/kb\/documents\/\d+\/raw/.test(resp.url())
                && resp.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await save.click();
        await saveResponse;

        await expect(page.getByTestId('toast-success')).toBeVisible({ timeout: 15_000 });

        // Switch to the History tab — a new `updated` audit row must appear
        // in addition to the seeded `promoted` row.
        await page.getByTestId('kb-tab-history').click();
        await expect(page.getByTestId('kb-history')).toBeVisible({ timeout: 10_000 });
        const updatedRow = page.locator(
            '[data-testid^="kb-history-"][data-event-type="updated"]',
        );
        // Fallback: assert any row whose text content contains "updated"
        // (the resource serialises event_type into the row template).
        await expect(
            updatedRow.first().or(page.getByTestId('kb-history').getByText('updated').first()),
        ).toBeVisible({ timeout: 15_000 });
    });

    test('failure — invalid frontmatter returns 422 with per-field error', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await page.getByTestId('kb-tree-node-policies/remote-work-policy.md').click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });
        await page.getByTestId('kb-tab-source').click();
        await expect(page.getByTestId('kb-source')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const cm = page.getByTestId('kb-editor-cm');
        const content = cm.locator('.cm-content');
        await content.click();
        // Replace the whole buffer with a new document that has an invalid
        // `status` value. Select-all + delete + retype.
        await page.keyboard.press('Control+A');
        await page.keyboard.press('Delete');
        const invalid =
            '---\nid: dec-x\nslug: remote-work-policy\ntype: decision\nstatus: NOT_A_VALID_STATUS\n---\n\n# body\n';
        await page.keyboard.type(invalid);

        await page.getByTestId('kb-editor-save').click();
        await expect(page.getByTestId('kb-editor-error-frontmatter')).toBeVisible({
            timeout: 10_000,
        });
        await expect(page.getByTestId('toast-error')).toBeVisible({ timeout: 10_000 });
    });

    test('failure injection — R4 storage 500 surfaces the generic error banner', async ({ page }) => {
        // R13: failure injection — real disk-write path is covered by
        // the "happy — edit, save, toast and new history row" scenario
        // above. The spy below is the only way to assert the 5xx error
        // surface deterministically without poisoning the test disk.
        // request interception against internal PATCH /raw.
        await page.route('**/api/admin/kb/documents/*/raw', (route) => {
            if (route.request().method() === 'PATCH') {
                return route.fulfill({
                    status: 500,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'failed to write markdown to disk' }),
                });
            }
            return route.fallback();
        });

        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await page.getByTestId('kb-tree-node-policies/remote-work-policy.md').click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });
        await page.getByTestId('kb-tab-source').click();
        await expect(page.getByTestId('kb-source')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const cm = page.getByTestId('kb-editor-cm');
        const content = cm.locator('.cm-content');
        await content.click();
        await page.keyboard.press('Control+End');
        await page.keyboard.type('\n\n<!-- trigger 500 -->\n');

        await page.getByTestId('kb-editor-save').click();
        await expect(page.getByTestId('kb-editor-error')).toBeVisible({ timeout: 10_000 });
    });

    test('cancel restores the buffer to the saved baseline', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await page.getByTestId('kb-tree-node-policies/remote-work-policy.md').click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });
        await page.getByTestId('kb-tab-source').click();
        await expect(page.getByTestId('kb-source')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const cm = page.getByTestId('kb-editor-cm');
        const content = cm.locator('.cm-content');
        // Snapshot the text before editing.
        const before = await content.textContent();
        expect(before).not.toBeNull();

        await content.click();
        await page.keyboard.press('Control+End');
        await page.keyboard.type('\n\nSHOULD BE REMOVED BY CANCEL\n');

        await expect(page.getByTestId('kb-editor-cancel')).toBeEnabled({ timeout: 10_000 });
        await page.getByTestId('kb-editor-cancel').click();

        // After cancel the buffer text should match the pre-edit snapshot.
        const after = await content.textContent();
        expect(after).toBe(before);
        await expect(page.getByTestId('kb-editor-cancel')).toBeDisabled();
    });
});
