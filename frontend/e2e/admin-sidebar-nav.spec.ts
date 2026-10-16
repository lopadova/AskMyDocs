import { chooseSelectOption } from './helpers';
import { expect } from '@playwright/test';
import { test } from './fixtures';

const sections = [
    { hub: 'overview', id: 'dashboard', url: /\/admin$/, heading: 'Dashboard' },
    { hub: 'knowledge', id: 'kb', url: /\/admin\/kb/, heading: 'Knowledge Base' },
    { hub: 'knowledge', id: 'insights', url: /\/admin\/insights$/, heading: 'AI Insights' },
    { hub: 'organisation', id: 'users', url: /\/admin\/users$/, heading: 'Users' },
    { hub: 'operations', id: 'maintenance', url: /\/admin\/maintenance$/, heading: 'Maintenance' },
] as const;

test('administration hubs lead to the original views and retain active context', async ({ page }) => {
    await page.goto('/app/chat');
    await chooseSelectOption(page.getByRole('combobox', { name: 'Workspace area' }), 'admin');
    for (const section of sections) {
        await page.getByTestId(`sidebar-hub-${section.hub}`).click();
        await page.getByTestId(`hub-link-${section.id}`).click();
        await expect(page).toHaveURL(section.url);
        await expect(page.getByRole('heading', { name: section.heading, level: 1 })).toBeVisible();
        await expect(page.getByTestId(`sidebar-nav-${section.id}`)).toHaveAttribute('aria-current', 'page');
        await expect(page.getByTestId(`sidebar-hub-${section.hub}`)).toHaveAttribute('aria-current', 'page');
    }
});

test('legacy aliases keep redirecting to the real views', async ({ page }) => {
    for (const [from, expected] of [
        ['/app/dashboard', /\/admin$/], ['/app/kb', /\/admin\/kb/],
        ['/app/insights', /\/admin\/insights$/], ['/app/users', /\/admin\/users$/],
        ['/app/maintenance', /\/admin\/maintenance$/],
    ] as const) {
        await page.goto(from);
        await expect(page).toHaveURL(expected);
        await expect(page.getByText(/Coming in Phase/i)).toHaveCount(0);
    }
});

test('the mobile drawer closes after navigation and retains a way to reopen it', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/app/chat');
    const menu = page.getByRole('button', { name: 'Open navigation' });
    await menu.click();
    await page.getByRole('dialog', { name: 'Primary navigation' }).getByRole('link', { name: 'Library' }).click();
    await expect(page).toHaveURL(/\/knowledge$/);
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(menu).toBeFocused();
    await menu.click();
    await page.keyboard.press('Escape');
    await expect(menu).toBeFocused();
});
