import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.9 — admin drag-and-drop KB upload.
 *
 * Runs against the REAL backend seeded by DemoSeeder. The E2E web server runs
 * the sync queue + the FAKE AI/embeddings provider (see playwright.config.ts
 * webServer.env), so the commit ingests inline with no external call — nothing
 * to stub (R13: zero interception on the happy path). The failure path uploads
 * an unsupported file type and asserts the real 422 surfaces in the DOM, so it
 * needs no injection marker either.
 */

const PROGRESS = '[data-testid^="kb-upload-batch-"][data-testid$="-progress"]';

test.describe('Admin KB Upload', () => {
    test('happy — stage → commit → progress → succeeded, doc appears in tree', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        await page.getByTestId('kb-upload-open').click();
        await expect(page.getByTestId('kb-upload-modal')).toBeVisible();

        // Pick the first real project (derived from the DB, R18) — never a literal.
        await page.getByTestId('kb-upload-project-select').selectOption({ index: 1 });

        await page.getByTestId('kb-upload-file-input').setInputFiles({
            name: 'e2e-upload-doc.md',
            mimeType: 'text/markdown',
            buffer: Buffer.from('# E2E Upload\n\nStaged then committed by the upload modal.'),
        });

        await page.getByTestId('kb-upload-stage').click();

        // Review phase — exactly one staged row.
        await expect(page.getByTestId('kb-upload-modal')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.locator('[data-testid^="kb-upload-item-"][data-status="staged"]')).toHaveCount(1);

        await page.getByTestId('kb-upload-commit').click();

        // Poll-driven progress — wait on the aggregate's data-* attrs, never a timeout.
        await expect(page.locator(PROGRESS)).toHaveAttribute('data-total', '1', { timeout: 15_000 });
        await expect(page.locator(PROGRESS)).toHaveAttribute('data-done', '1', { timeout: 30_000 });
        await expect(page.locator(PROGRESS)).toHaveAttribute('data-failed', '0');
        await expect(page.getByTestId('kb-upload-modal')).toHaveAttribute('data-state', 'ready');

        // Close — the tree refetches and the new doc surfaces.
        await page.getByTestId('kb-upload-done-close').click();
        await expect(page.getByTestId('kb-tree-node-e2e-upload-doc.md')).toBeVisible({ timeout: 15_000 });
    });

    test('failure — unsupported file type surfaces a 422 in the DOM', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        await page.getByTestId('kb-upload-open').click();
        await page.getByTestId('kb-upload-project-select').selectOption({ index: 1 });

        // setInputFiles bypasses the `accept` filter, so the rejection is the
        // BE's job — the real StageKbUploadRequest returns 422 for .exe.
        await page.getByTestId('kb-upload-file-input').setInputFiles({
            name: 'malware.exe',
            mimeType: 'application/octet-stream',
            buffer: Buffer.from('nope'),
        });

        await page.getByTestId('kb-upload-stage').click();

        await expect(page.getByTestId('kb-upload-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-upload-error')).toContainText(/unsupported/i);
        await expect(page.getByTestId('kb-upload-modal')).toHaveAttribute('data-state', 'error');
    });
});
