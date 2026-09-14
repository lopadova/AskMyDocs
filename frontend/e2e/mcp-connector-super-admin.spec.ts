import { expect, test } from '@playwright/test';

test.describe('MCP connector — super-admin', () => {
    test('creates a shared connection and governs discovered tools and resources', async ({ page }) => {
        const label = `Live fixture ${Date.now()}`;
        await page.goto('/app/admin/connectors');
        const panel = page.getByTestId('mcp-connections-shared');
        await expect(panel).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('connector-mcp-add-connection').click();
        const form = page.getByTestId('mcp-connection-form-shared');
        await form.getByLabel('Name').fill('E2E MCP fixture');
        await form.getByLabel('Label').fill(label);
        await form.getByLabel('MCP endpoint', { exact: true }).fill('http://127.0.0.1:3536/mcp');
        await form.getByRole('radio', { name: /^No authentication/ }).check();
        await form.getByRole('button', { name: 'Connect and discover' }).click();

        const card = panel.locator('article').filter({ hasText: label });
        await expect(card).toBeVisible({ timeout: 15_000 });
        await card.getByRole('button', { name: `Expand ${label}` }).click();
        await expect(card).toContainText('Protocol 2026-07-28');
        await expect(card).toContainText('Search documents');
        await expect(card).toContainText('Update document');
        await expect(card).toContainText('Employee handbook');

        const search = card.getByText('Search documents').locator('..').locator('..').getByRole('checkbox');
        const update = card.getByText('Update document').locator('..').locator('..').getByRole('checkbox');
        await expect(search).toBeChecked();
        await expect(update).not.toBeChecked();
        await expect(card).toContainText('confirmation');

        const resource = card.getByText('Employee handbook').locator('..').locator('..').getByRole('checkbox');
        await resource.click();
        await expect(resource).toBeChecked();
        await card.getByRole('button', { name: `More actions for ${label}` }).click();
        await expect(card.getByRole('menuitem', { name: 'Sync resources' })).toBeVisible();
    });

    test('connects a protected MCP server through OAuth without exposing a token', async ({ page }) => {
        const label = `Protected live fixture ${Date.now()}`;
        await page.goto('/app/admin/connectors');
        const panel = page.getByTestId('mcp-connections-shared');
        await expect(panel).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('connector-mcp-add-connection').click();
        const form = page.getByTestId('mcp-connection-form-shared');
        await form.getByLabel('Name').fill('OAuth E2E MCP');
        await form.getByLabel('Label').fill(label);
        await form.getByLabel('MCP endpoint', { exact: true }).fill('http://127.0.0.1:3536/oauth/mcp');
        await form.getByRole('button', { name: 'Continue with OAuth' }).click();

        await expect(page.getByTestId('mcp-oauth-result')).toContainText('OAuth connection completed', { timeout: 15_000 });
        const card = panel.locator('article').filter({ hasText: label });
        await expect(card).toContainText('active');
        await card.getByRole('button', { name: `Expand ${label}` }).click();
        await expect(card).toContainText('Search documents');
        await expect(page).not.toHaveURL(/access_token|refresh_token|authorization-code/);
        await expect(page.locator('body')).not.toContainText('e2e-access-token');
    });
});
