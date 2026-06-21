import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * Team switcher — viewer project (failure path, R12).
 *
 * viewer@demo.local has memberships ONLY in the `default` tenant
 * (DemoSeeder deliberately gives the acme membership to the admin
 * account alone). The switcher must therefore:
 *   - show the single `Default` team,
 *   - render DISABLED (visible but inert — no menu to open),
 *   - never offer `acme`.
 *
 * R13: real backend, real seeded data, no interception.
 */

test.describe('Team switcher (viewer)', () => {
    test('viewer without multi-team memberships gets a disabled single-team trigger', async ({
        page,
    }) => {
        await page.goto('/app/admin');

        const trigger = page.getByTestId('team-switcher-trigger');
        await expect(trigger).toBeVisible({ timeout: 15_000 });
        await expect(trigger).toHaveText(/Default/);
        await expect(trigger).toBeDisabled();

        // Clicking a disabled trigger opens nothing — and acme is
        // nowhere in the DOM.
        await trigger.click({ force: true });
        await expect(page.getByTestId('team-switcher-menu')).not.toBeVisible();
        await expect(page.getByTestId('team-switcher-item-acme')).toHaveCount(0);
    });
});
