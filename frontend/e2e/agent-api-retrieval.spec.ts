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

        const activity = page.getByTestId('agent-activity-bar');
        await expect(activity).toBeVisible({ timeout: 30_000 });
        await expect(activity).toHaveAttribute('data-state', 'settled', { timeout: 30_000 });
        await expect(activity).toContainText('La risposta è pronta.');
        await expect(activity).toContainText('Sto chiamando Cerca cliente.');
        await expect(activity).toContainText('Sto chiamando Recupera ordini.');
        await expect(activity).toContainText(/Completate 2 richieste API/);

        const answer = page.locator('[data-testid^="chat-message-"][data-role="assistant"]').last();
        await expect(answer).toContainText('Ho trovato 3 ordini per Tizio: A-100, A-101, A-102.', { timeout: 30_000 });
    });

    test('widget uses the same loop and exposes API provenance', async ({ page }) => {
        await page.goto('/widget-demo?mode=inline');
        await page.getByTestId('askmydocs-widget-input').fill('Dammi tutti gli ordini di Tizio');
        await page.getByTestId('askmydocs-widget-send').click();

        const activity = page.getByTestId('askmydocs-widget-agent-activity');
        await expect(activity).toBeVisible({ timeout: 30_000 });
        await expect(activity).toHaveAttribute('data-state', 'settled', { timeout: 30_000 });
        await expect(activity).toContainText('La risposta è pronta.');
        await expect(page.getByTestId('askmydocs-widget-message').last()).toContainText(
            'Ho trovato 3 ordini per Tizio: A-100, A-101, A-102.',
            { timeout: 30_000 },
        );
        await expect(page.getByTestId('askmydocs-widget-citation').filter({ hasText: 'get_orders' })).toBeVisible();
    });

    test('a real upstream 503 is visible and produces a partial localized answer', async ({ page }) => {
        await page.goto('/app/chat');
        const controls = composer(page);
        await controls.input.fill('Dammi gli ordini di Tizio e simula 503');
        await controls.send.click();

        const activity = page.getByTestId('agent-activity-bar');
        await expect(activity).toHaveAttribute('data-state', 'settled', { timeout: 30_000 });
        await expect(activity).toContainText('Non è stato possibile completare Cerca cliente.');
        const answer = page.locator('[data-testid^="chat-message-"][data-role="assistant"]').last();
        await expect(answer).toContainText('Non ho potuto recuperare gli ordini', { timeout: 30_000 });
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
