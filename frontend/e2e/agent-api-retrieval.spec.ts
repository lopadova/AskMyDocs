import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { composer } from './helpers';
import { E2E_BASE_URL, resetDb, seedDb } from './setup-helpers';

/*
 * Real end-to-end boundary: no page.route interception. E2eAgentRetrievalSeeder
 * installs two active API tools and the testing-only FakeProvider emits the
 * same structured plan a real model would: customer lookup → dependent orders
 * lookup → paginated collection → grounded synthesis. QUEUE_CONNECTION=sync
 * keeps the test self-contained while still running ExecuteAgentRunJob and the
 * resumable SSE endpoint.
 */
test.describe.configure({ timeout: 60_000, mode: 'serial' });

test.describe('Agentic API retrieval — real backend and SSE', () => {
    test.beforeEach(async ({ page, context }) => {
        // This spec deliberately owns its page-only session lifecycle. The
        // shared fixture also logs in Playwright's separate top-level request
        // jar, which is unnecessary here and can race a just-reset DB session.
        await resetDb(page);
        await seedDb(page, 'DemoSeeder');
        await loginPage(page, context);
        await seedDb(page, 'E2eAgentRetrievalSeeder');
    });

    test('chat chains customer and paginated orders with localized activity', async ({ page }) => {
        await page.goto('/app/chat');
        const controls = composer(page);
        await controls.input.fill('Dammi tutti gli ordini di Tizio');
        await controls.send.click();

        const information = page.getByRole('button', { name: 'Informazioni sulla risposta' });
        await expect(information).toBeVisible({ timeout: 30_000 });
        await information.click();
        const activity = page.getByRole('dialog', { name: 'Informazioni sulla risposta' });
        await expect(activity).toContainText('La risposta è pronta.');
        await expect(activity).toContainText('Sto chiamando Cerca cliente.');
        await expect(activity).toContainText('Sto chiamando Recupera ordini.');
        await expect(activity).toContainText('Cerca cliente completato.');
        await expect(activity).toContainText('Recupera ordini completato.');
        await page.getByRole('button', { name: 'Chiudi informazioni' }).click();

        const answer = page.locator('[data-testid^="chat-message-"][data-role="assistant"]').last();
        for (const text of ['Ordine A-100: totale 120.', 'Ordine A-101: totale 80.', 'Ordine A-102: totale 45.']) {
            await expect(answer).toContainText(text, { timeout: 30_000 });
        }
    });

    test('widget uses the same loop and exposes API provenance', async ({ page }) => {
        await mountInlineWidget(page);
        await page.getByTestId('askmydocs-widget-input').fill('Dammi tutti gli ordini di Tizio');
        await page.getByTestId('askmydocs-widget-send').click();

        const activity = page.getByTestId('askmydocs-widget-agent-activity');
        await expect(activity).toBeVisible({ timeout: 30_000 });
        await expect(activity).toHaveAttribute('data-state', 'settled', { timeout: 30_000 });
        await expect(activity).toContainText('La risposta è pronta.');
        for (const text of ['Ordine A-100: totale 120.', 'Ordine A-101: totale 80.', 'Ordine A-102: totale 45.']) {
            await expect(page.getByTestId('askmydocs-widget-message').last()).toContainText(text, { timeout: 30_000 });
        }
        await expect(page.getByTestId('askmydocs-widget-citation').filter({ hasText: 'get_orders' })).toBeVisible();
    });

    test('a real upstream 503 is visible and produces a partial localized answer', async ({ page }) => {
        await page.goto('/app/chat');
        const controls = composer(page);
        await controls.input.fill('Dammi gli ordini di Tizio e simula HTTP503');
        await controls.send.click();

        const information = page.getByRole('button', { name: 'Informazioni sulla risposta' });
        await expect(information).toBeVisible({ timeout: 30_000 });
        await information.click();
        const activity = page.getByRole('dialog', { name: 'Informazioni sulla risposta' });
        await expect(activity).toContainText('Non è stato possibile completare Cerca cliente.');
        await page.getByRole('button', { name: 'Chiudi informazioni' }).click();
        const answer = page.locator('[data-testid^="chat-message-"][data-role="assistant"]').last();
        await expect(answer).toContainText('Non ho potuto completare la chiamata alla sorgente esterna.', { timeout: 30_000 });
        await expect(answer).toContainText('Le altre fonti consultate non bastano per rispondere a questa richiesta.');
        await expect(answer).not.toContainText('A-100');
        await expect(answer).not.toContainText('A-101');
        await expect(answer).not.toContainText('A-102');
    });
});

async function loginPage(page: Page, context: BrowserContext): Promise<void> {
    const csrf = await page.request.get('/sanctum/csrf-cookie');
    expect(csrf.ok()).toBe(true);
    const cookies = await context.cookies(E2E_BASE_URL);
    const token = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    if (!token) throw new Error('Missing page XSRF-TOKEN after database reset.');

    const login = await page.request.post('/api/auth/login', {
        data: { email: 'admin@demo.local', password: 'password' },
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(token.value),
            Accept: 'application/json',
        },
    });
    if (!login.ok()) {
        throw new Error(`Agent E2E login failed: ${login.status()} ${await login.text()}`);
    }
}

/** Mount the public bundle on a separate host origin; all widget APIs remain real. */
async function mountInlineWidget(page: Page): Promise<void> {
    await page.goto('/healthz');
    const host = new URL(page.url());
    const configuredApi = process.env.E2E_WIDGET_API_BASE_URL;
    const api = configuredApi ? new URL(configuredApi) : new URL(host);
    if (!configuredApi) api.hostname = host.hostname === '127.0.0.1' ? 'localhost' : '127.0.0.1';
    if (api.origin === host.origin) throw new Error('Widget test requires a separate API origin.');
    await page.setContent('<!doctype html><html lang="it"><body><main><div id="widget-host" style="height:700px"></div></main></body></html>');
    const setup = page.waitForResponse(response => response.url().startsWith(api.origin + '/api/widget/setup'));
    await page.evaluate(async apiBase => {
        (window as Window & { AskMyDocsWidget?: Record<string, unknown> }).AskMyDocsWidget = {
            key: 'pk_demo_local', apiBase, mode: 'inline', mount: '#widget-host',
        };
        await new Promise<void>((resolve, reject) => {
            const script = document.createElement('script');
            script.src = '/widget/askmydocs-widget.js';
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Widget bundle failed to load.'));
            document.body.append(script);
        });
    }, api.origin);
    const response = await setup;
    expect(response.ok()).toBe(true);
    expect((await response.json()).data_agent).toEqual({ enabled: true, tool_count: 2 });
    await expect(page.getByTestId('askmydocs-widget-input')).toBeEnabled();
}
