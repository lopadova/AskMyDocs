import { expect, test } from '@playwright/test';
import { resetDb } from './setup-helpers';

/*
 * v8.30/W1 — fullscreen + authenticated-history E2E.
 *
 * Happy paths use the real demo host boundary, real ik_→wu_ exchange, real
 * widget middleware/controllers and real DB. No /api/widget route is stubbed.
 */
test.describe('KITT fullscreen authenticated history — real data', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeEach(async ({ page }) => {
        await resetDb(page);
    });

    test('restores the open session after a fullscreen reload', async ({ page }) => {
        const question = 'Fullscreen restore question 830';
        const currentResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith('/api/widget/sessions/current') &&
                response.request().method() === 'GET',
        );

        await page.goto('/widget-demo?mode=fullscreen&user_auth=1&subject=restore-user');

        const panel = page.getByTestId('askmydocs-widget-panel');
        await expect(panel).toBeVisible({ timeout: 15_000 });
        await expect(panel).toHaveAttribute('role', 'region');
        await expect(panel).toHaveAttribute('data-open', 'true');
        await expect(panel).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(panel).toHaveAttribute('aria-busy', 'false');
        await expect(page.getByTestId('askmydocs-widget-launcher')).toBeHidden();
        expect((await currentResponse).status()).toBe(204);

        const viewport = page.viewportSize();
        const box = await panel.boundingBox();
        expect(box).not.toBeNull();
        expect(Math.round(box!.width)).toBe(viewport!.width);
        expect(Math.round(box!.height)).toBe(viewport!.height);

        await page.getByTestId('askmydocs-widget-input').fill(question);
        await page.getByTestId('askmydocs-widget-send').click();
        await expect(page.getByTestId('askmydocs-widget-message').last()).toContainText(
            /remote|remoto|knowledge base/i,
            { timeout: 15_000 },
        );

        const restoredCurrent = page.waitForResponse(
            (response) =>
                response.url().endsWith('/api/widget/sessions/current') &&
                response.request().method() === 'GET',
        );
        await page.reload();
        expect((await restoredCurrent).status()).toBe(200);

        await expect(panel).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('askmydocs-widget-message').filter({
            hasText: question,
        })).toHaveCount(1);
        await expect(page.getByTestId('askmydocs-widget-input')).toBeEnabled();
    });

    test('keeps identities isolated on the same fullscreen widget key', async ({ page }) => {
        const aliceQuestion = 'Alice-only fullscreen history 830';
        await page.goto('/widget-demo?mode=fullscreen&user_auth=1&subject=alice-830');
        await expect(page.getByTestId('askmydocs-widget-panel')).toHaveAttribute(
            'data-state',
            'ready',
            { timeout: 15_000 },
        );
        await page.getByTestId('askmydocs-widget-input').fill(aliceQuestion);
        await page.getByTestId('askmydocs-widget-send').click();
        await expect(page.getByTestId('askmydocs-widget-message').last()).toContainText(
            /remote|remoto|knowledge base/i,
            { timeout: 15_000 },
        );

        await page.goto('/widget-demo?mode=fullscreen&user_auth=1&subject=bob-830');
        await expect(page.getByTestId('askmydocs-widget-panel')).toHaveAttribute(
            'data-state',
            'ready',
            { timeout: 15_000 },
        );
        await expect(page.getByTestId('askmydocs-widget-message').filter({
            hasText: aliceQuestion,
        })).toHaveCount(0);

        await page.goto('/widget-demo?mode=fullscreen&user_auth=1&subject=alice-830');
        await expect(page.getByTestId('askmydocs-widget-message').filter({
            hasText: aliceQuestion,
        })).toHaveCount(1, { timeout: 15_000 });
    });

    test('surfaces host-token failure and keeps the composer disabled', async ({ page }) => {
        await page.goto(
            '/widget-demo?mode=fullscreen&user_auth=1&auth_failure=1&subject=failure-user',
        );

        const panel = page.getByTestId('askmydocs-widget-panel');
        await expect(panel).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
        await expect(panel).toHaveAttribute('aria-busy', 'false');
        await expect(page.getByTestId('askmydocs-widget-error')).toContainText(
            /demo host could not authenticate|Impossibile inizializzare/i,
        );
        await expect(page.getByTestId('askmydocs-widget-input')).toBeDisabled();
        await expect(page.getByTestId('askmydocs-widget-send')).toBeDisabled();
    });
});
