import { expect, test } from '@playwright/test';

test.describe('MCP connector — super-admin', () => {
    test('creates a shared connection and governs discovered tools and resources', async ({ page }) => {
        await page.goto('/app/admin/connectors');
        const panel = page.getByTestId('mcp-connections-shared');
        await expect(panel).toBeVisible({ timeout: 15_000 });

        await panel.getByRole('button', { name: 'Add MCP connection' }).click();
        const form = page.getByTestId('mcp-connection-form-shared');
        await form.getByLabel('Name').fill('E2E MCP fixture');
        await form.getByLabel('Label').fill('Live fixture');
        await form.getByLabel('MCP endpoint').fill('http://127.0.0.1:3536/mcp');
        await form.getByRole('button', { name: 'Connect and discover' }).click();

        const card = panel.locator('article').filter({ hasText: 'Live fixture' });
        await expect(card).toBeVisible({ timeout: 15_000 });
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
        await expect(card.getByRole('button', { name: 'Sync resources' })).toBeVisible();
    });
});
