import { expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

seededTest.describe('Admin AI Act compliance scaffold', () => {
    seededTest('happy — admin opens the scaffold from the admin rail', async ({ page }) => {
        await page.goto('/app/admin');

        await page.getByTestId('admin-rail-ai-act-compliance').click();
        await expect(page).toHaveURL(/\/app\/admin\/ai-act-compliance$/);
        await expect(page.getByTestId('admin-ai-act-compliance')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-ai-act-compliance')).toHaveAttribute('data-state', 'ready');
        await expect(page.getByTestId('admin-ai-act-compliance-title')).toHaveText('AI Act compliance');
        await expect(page.getByTestId('admin-rail-ai-act-compliance')).toHaveAttribute('data-active', 'true');
    });
});
