import { expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

seededTest.describe('Admin AI Act compliance scaffold', () => {
    seededTest('happy — admin opens AI Act from the unified sidebar', async ({ page }) => {
        await page.goto('/app/admin');

        // The secondary AdminShell rail is gone; every section is reached from
        // the single primary sidebar now.
        await page.getByTestId('sidebar-nav-ai-act-compliance').click();
        await expect(page).toHaveURL(/\/app\/admin\/ai-act-compliance$/);
        await expect(page.getByTestId('admin-ai-act-compliance')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-ai-act-compliance')).toHaveAttribute('data-state', 'ready');
        await expect(page.getByTestId('admin-ai-act-compliance-title')).toHaveText('AI Act compliance');
        await expect(page.getByTestId('sidebar-nav-ai-act-compliance')).toHaveAttribute('aria-current', 'page');
    });
});
