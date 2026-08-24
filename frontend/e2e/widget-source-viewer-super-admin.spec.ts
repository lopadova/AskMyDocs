import type { Locator, Page } from '@playwright/test';
import { test, expect } from '@playwright/test';
import { resetAndLoginWidgetSuperAdmin } from './helpers/widget-super-admin';
import { seedDb } from './setup-helpers';

type WidgetMode = 'helper' | 'inline' | 'fullscreen';

interface CreatedWidgetKey {
    id: number;
    publicKey: string;
}

/*
 * Cited-source viewer — real widget boundary.
 *
 * The happy paths do not stub /api/widget/*: E2eStreamSeeder ingests one
 * vector-searchable document through DocumentIngestor, the key is created
 * through the real tenant-scoped project select, and the embed bundle talks
 * to the real session + preview controllers. The only injected response is
 * the first preview request in the explicit 503/retry scenario.
 */
test.describe.configure({ mode: 'serial', timeout: 120_000 });

test.beforeEach(async ({ page, context }) => {
    await resetAndLoginWidgetSuperAdmin(page, context);
});

async function createWidgetKey(page: Page, label: string): Promise<CreatedWidgetKey> {
    await seedDb(page, 'E2eStreamSeeder');
    await page.goto('/app/admin/widget');
    await expect(page.getByTestId('admin-widget-keys-view')).toBeVisible({ timeout: 15_000 });

    await page.getByTestId('admin-widget-keys-create-btn').click();
    await page.getByTestId('admin-widget-keys-label').fill(label);

    const project = page.getByTestId('admin-widget-keys-project');
    await expect(project).toBeEnabled({ timeout: 15_000 });
    await project.selectOption('hr-portal');
    await expect(project).toHaveValue('hr-portal');

    await page
        .getByTestId('admin-widget-keys-origins')
        .fill('http://127.0.0.1:8000\nhttp://localhost:8000');

    const createResponse = page.waitForResponse(
        (response) =>
            response.url().endsWith('/api/admin/widget-keys')
            && response.request().method() === 'POST',
        { timeout: 15_000 },
    );
    await page.getByTestId('admin-widget-keys-create-submit').click();
    const response = await createResponse;
    if (!response.ok()) {
        throw new Error(`POST /api/admin/widget-keys failed: ${response.status()} ${await response.text()}`);
    }
    const body = await response.json() as {
        data: { id: number; project_key: string };
        public_key: string;
    };
    expect(body.data.project_key).toBe('hr-portal');
    expect(body.public_key).toMatch(/^pk_/);

    return { id: body.data.id, publicKey: body.public_key };
}

async function mountWidget(page: Page, publicKey: string, mode: WidgetMode): Promise<void> {
    await page.goto('/healthz');
    await page.setContent(`<!doctype html>
        <html lang="it">
          <head>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
              html, body { width: 100%; min-height: 100%; margin: 0; }
              #widget-host { width: min(760px, 100%); height: 700px; margin: 0 auto; }
            </style>
          </head>
          <body><main><div id="widget-host"></div></main></body>
        </html>`);

    await page.evaluate(
        async ({ key, widgetMode }) => {
            const configuredWindow = window as Window & {
                AskMyDocsWidget?: Record<string, unknown>;
            };
            // Mirror /widget-demo's real embed boundary so every GET and POST
            // carries an Origin header for the widget allow-list middleware.
            const apiHost = window.location.hostname === '127.0.0.1'
                ? 'localhost'
                : '127.0.0.1';
            const apiBase = `${window.location.protocol}//${apiHost}:${window.location.port}`;
            configuredWindow.AskMyDocsWidget = {
                key,
                apiBase,
                mode: widgetMode,
                ...(widgetMode === 'inline' ? { mount: '#widget-host' } : {}),
            };

            await new Promise<void>((resolve, reject) => {
                const script = document.createElement('script');
                script.src = '/widget/askmydocs-widget.js';
                script.onload = () => resolve();
                script.onerror = () => reject(new Error('Widget bundle could not be loaded.'));
                document.body.append(script);
            });
        },
        { key: publicKey, widgetMode: mode },
    );

    const panel = page.getByTestId('askmydocs-widget-panel');
    if (mode === 'helper') {
        await expect(panel).toHaveAttribute('role', 'dialog');
        const launcher = page.getByTestId('askmydocs-widget-launcher');
        await expect(launcher).toBeVisible({ timeout: 15_000 });
        await launcher.click();
        await expect(panel).toHaveAttribute('data-open', 'true');
        await expect(panel).toBeVisible();
    } else {
        await expect(panel).toBeVisible({ timeout: 15_000 });
        await expect(panel).toHaveAttribute('role', 'region');
        await expect(panel).toHaveAttribute('data-open', 'true');
        await expect(page.getByTestId('askmydocs-widget-launcher')).toBeHidden();
    }
    await expect(page.getByTestId('askmydocs-widget-input')).toBeEnabled({ timeout: 15_000 });
}

async function askForCitedDocument(page: Page): Promise<Locator> {
    await page
        .getByTestId('askmydocs-widget-input')
        .fill('Quanti giorni a settimana posso lavorare da casa?');
    await page.getByTestId('askmydocs-widget-send').click();

    await expect(page.getByTestId('askmydocs-widget-message').last()).toContainText(
        /remotely|remoto|knowledge base/i,
        { timeout: 20_000 },
    );
    const citation = page.getByTestId('askmydocs-widget-citation').first();
    await expect(citation).toBeVisible({ timeout: 20_000 });
    await expect(citation).toHaveAttribute('data-openable', 'true');
    await expect(page.getByTestId('askmydocs-widget-sources-open')).toHaveText('Fonti · 1');

    return citation;
}

async function expectRealDocumentViewer(page: Page, citation: Locator): Promise<void> {
    const previewResponse = page.waitForResponse(
        (response) =>
            response.url().includes('/api/widget/sessions/')
            && response.url().includes('/documents/')
            && response.url().endsWith('/preview'),
        { timeout: 15_000 },
    );
    await citation.click();
    const response = await previewResponse;
    if (!response.ok()) {
        throw new Error(`GET cited document failed: ${response.status()} ${await response.text()}`);
    }
    const viewer = page.getByTestId('askmydocs-widget-source-viewer');
    await expect(viewer).toBeVisible({ timeout: 15_000 });
    await expect(viewer).toHaveAttribute('open', '');
    await expect(page.getByTestId('askmydocs-widget-source-content')).toHaveAttribute(
        'data-state',
        'ready',
        { timeout: 15_000 },
    );
    await expect(viewer.locator('.amd-source-title')).toContainText('e2e-remote-work');
    await expect(viewer.locator('.amd-source-markdown')).toContainText(
        'Employees may work remotely up to 3 days per week',
    );
    await expect(page.getByTestId('askmydocs-widget-source-evidence')).toContainText(
        'Employees may work remotely up to 3 days per week',
    );

    await page.getByTestId('askmydocs-widget-source-close').click();
    await expect(viewer).toBeHidden();
    await expect(citation).toBeFocused();
}

test.describe('Widget cited-source viewer — real document', () => {
    test('opens the cited indexed document in helper, inline and fullscreen layouts', async ({
        page,
    }) => {
        const { publicKey } = await createWidgetKey(page, `Viewer layouts ${Date.now()}`);

        for (const mode of ['helper', 'inline', 'fullscreen'] as const) {
            await test.step(mode, async () => {
                await mountWidget(page, publicKey, mode);
                const citation = await askForCitedDocument(page);
                await expectRealDocumentViewer(page, citation);

                if (mode === 'fullscreen') {
                    const viewport = page.viewportSize();
                    const panel = await page.getByTestId('askmydocs-widget-panel').boundingBox();
                    expect(panel).not.toBeNull();
                    expect(Math.round(panel!.width)).toBe(viewport!.width);
                    expect(Math.round(panel!.height)).toBe(viewport!.height);
                }
            });
        }
    });

    test('uses the fullscreen source dialog and top selector below 640px', async ({ page }) => {
        const { publicKey } = await createWidgetKey(page, `Viewer mobile ${Date.now()}`);
        await page.setViewportSize({ width: 390, height: 844 });
        await mountWidget(page, publicKey, 'inline');
        const citation = await askForCitedDocument(page);
        await citation.click();

        const viewer = page.getByTestId('askmydocs-widget-source-viewer');
        await expect(viewer).toBeVisible({ timeout: 15_000 });
        await expect(viewer.locator('.amd-source-sidebar')).toBeHidden();
        const selector = page.getByTestId('askmydocs-widget-source-select');
        await expect(selector).toBeVisible();
        await expect(selector.locator('option')).toHaveCount(1);
        await expect(page.getByTestId('askmydocs-widget-source-content')).toHaveAttribute(
            'data-state',
            'ready',
            { timeout: 15_000 },
        );

        const box = await viewer.boundingBox();
        expect(box).not.toBeNull();
        expect(Math.abs(box!.width - 390)).toBeLessThanOrEqual(1);
        expect(Math.abs(box!.height - 844)).toBeLessThanOrEqual(1);
    });

    test('shows the 503 state and retries the same real session-scoped document', async ({
        page,
    }) => {
        const { publicKey } = await createWidgetKey(page, `Viewer retry ${Date.now()}`);
        await mountWidget(page, publicKey, 'helper');
        const citation = await askForCitedDocument(page);

        let previewAttempts = 0;
        // R13: failure injection — the happy-path preview flow is covered by the
        // other tests in this file against real seeded data; here we force a
        // one-shot 503 on the first preview fetch to exercise the 503-state +
        // retry UX, then let the real endpoint serve the retry.
        await page.route('**/api/widget/sessions/*/documents/*/preview', async (route) => {
            previewAttempts += 1;
            if (previewAttempts === 1) {
                await route.fulfill({
                    status: 503,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        error: 'temporarily_unavailable',
                        message: 'Document preview is temporarily unavailable.',
                    }),
                });
                return;
            }
            await route.continue();
        });

        await citation.click();
        await expect(page.getByTestId('askmydocs-widget-source-error')).toBeVisible({
            timeout: 15_000,
        });
        await expect(page.getByTestId('askmydocs-widget-source-content')).toHaveAttribute(
            'data-state',
            'error',
        );

        await page.getByTestId('askmydocs-widget-source-retry').click();
        await expect(page.getByTestId('askmydocs-widget-source-content')).toHaveAttribute(
            'data-state',
            'ready',
            { timeout: 15_000 },
        );
        await expect(page.locator('.amd-source-markdown')).toContainText(
            'Employees may work remotely up to 3 days per week',
        );
        expect(previewAttempts).toBe(2);
    });
});
