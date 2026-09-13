import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.9 — admin drag-and-drop KB upload.
 *
 * Runs against the REAL backend seeded by DemoSeeder, with the FAKE
 * AI/embeddings provider — nothing to stub (R13: zero interception on the
 * happy path). Per-file progress reconciles via Laravel's queue lifecycle
 * events (App\Listeners\KbUploadBatchItemProgress); how the job runs depends
 * on the queue connection:
 *   - LOCAL `npm run e2e`: playwright.config.ts's webServer inherits the
 *     default `sync` queue, so commit ingests inline and the events fire
 *     synchronously.
 *   - CI: QUEUE_CONNECTION=database (async) + a dedicated
 *     `php artisan queue:work --queue=kb-ingest,default` worker drains the
 *     committed IngestDocumentJob (see .github/workflows/tests.yml). Async is
 *     deliberate (R38) — a sync queue would make every KB save block on the
 *     full ingest pipeline and time out admin-kb-edit / admin-journey.
 * Either way the commit→queue→progress→succeeded chain completes; the happy
 * path polls the aggregate's data-* attrs until done. The failure path uploads
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

        // R18 — the picker filters on the extensions the real /api/auth/me
        // delivers (SourceType::knownExtensions(), images only with OCR on),
        // not on a list the SPA keeps: the two must match byte for byte.
        const me = await (await page.request.get('/api/auth/me')).json() as { kb_upload: { accepted_extensions: string[] } };
        expect(me.kb_upload.accepted_extensions).toContain('md');
        await expect(page.getByTestId('kb-upload-file-input')).toHaveAttribute(
            'accept',
            me.kb_upload.accepted_extensions.map((e) => `.${e}`).join(','),
        );

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

    /*
     * v8.36 / ADR 0029 — the OCR cost line on the review step. The E2E
     * server runs with the DEFAULT `KB_OCR_ENABLED=false`, so this is the
     * OFF-state contract (R43): the line says OCR is disabled, and an image
     * is refused by the real StageKbUploadRequest with a 422 in the DOM.
     * The ON state is covered by KbUploadOcrTest (PHPUnit) and the
     * OcrEstimateLine vitest — no interception, no fake flag flip here (R13).
     */
    test('ocr OFF — review step says OCR is disabled and an image is refused with a 422', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        await page.getByTestId('kb-upload-open').click();
        await page.getByTestId('kb-upload-project-select').selectOption({ index: 1 });

        await page.getByTestId('kb-upload-file-input').setInputFiles({
            name: 'e2e-ocr-estimate.md',
            mimeType: 'text/markdown',
            buffer: Buffer.from('# OCR estimate\n\nA markdown file never needs OCR.'),
        });
        await page.getByTestId('kb-upload-stage').click();
        await expect(page.getByTestId('kb-upload-modal')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        const line = page.getByTestId('kb-upload-ocr-estimate');
        await expect(line).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(line).toHaveAttribute('data-ocr-enabled', 'false');
        await expect(line).toContainText('KB_OCR_ENABLED=false');
        await expect(line).toHaveAttribute('role', 'status');

        // Back out (cancel closes the modal) and try an image in a fresh
        // batch: with OCR off the real request answers 422.
        await page.getByTestId('kb-upload-cancel').click();
        await expect(page.getByTestId('kb-upload-modal')).toBeHidden();
        await page.getByTestId('kb-upload-open').click();
        await expect(page.getByTestId('kb-upload-modal')).toBeVisible();
        await page.getByTestId('kb-upload-project-select').selectOption({ index: 1 });
        await page.getByTestId('kb-upload-file-input').setInputFiles({
            name: 'scan.png',
            mimeType: 'image/png',
            buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64'),
        });
        await page.getByTestId('kb-upload-stage').click();
        await expect(page.getByTestId('kb-upload-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-upload-error')).toContainText(/unsupported/i);
        await expect(page.getByTestId('kb-upload-modal')).toHaveAttribute('data-state', 'error');
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
