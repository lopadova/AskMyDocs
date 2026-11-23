import { expect, test } from '@playwright/test';
import { resetAndLoginWidgetSuperAdmin } from './helpers/widget-super-admin';

test.describe.configure({ mode: 'serial', timeout: 90_000 });

test.beforeEach(async ({ page, context }) => {
    await resetAndLoginWidgetSuperAdmin(page, context);
});

for (const access of ['public', 'authenticated'] as const) {
    test(`creates and resumes the ${access} widget setup`, async ({ page }) => {
        await page.goto('/app/admin/widget');
        await page.getByTestId('admin-widget-tab-setup').click();
        await page.getByTestId('widget-setup-new').click();
        await page.getByTestId('widget-setup-name').fill('Employee documentation');
        await page.getByTestId('widget-setup-access').selectOption(access);
        await page.getByTestId('widget-setup-audience').fill('Employees');
        await page.getByTestId('widget-setup-topics').fill('Remote work policy');
        await page.getByTestId('widget-setup-create').click();
        await expect(page.getByTestId('widget-setup-handoff')).toContainText('Remote work policy');
        const download = page.waitForEvent('download');
        await page.getByTestId('widget-setup-download').click();
        expect((await download).suggestedFilename()).toBe('employee-documentation-widget-handoff.md');

        await page.reload();
        await page.getByTestId('admin-widget-tab-setup').click();
        if (access === 'public') {
            await page.getByTestId('widget-setup-new').click();
            await page.getByTestId('widget-setup-name').fill('Employee documentation');
            await page.getByTestId('widget-setup-audience').fill('Employees');
            await page.getByTestId('widget-setup-topics').fill('Remote work policy');
            await page.getByTestId('widget-setup-create').click();
            await page.getByTestId('widget-setup-resume-duplicate').click();
        } else {
            await page.getByTestId(/^widget-setup-resume-/).click();
        }
        await expect(page.getByTestId('widget-setup-continue-embed')).toBeDisabled();
        await page.getByTestId('widget-setup-upload').click();
        await expect(page.getByTestId('kb-upload-project-select')).toBeDisabled();
        await page.getByTestId('kb-upload-file-input').setInputFiles({
            name: 'remote-work.md',
            mimeType: 'text/markdown',
            buffer: Buffer.from('# Remote work policy\n\nEmployees may work remotely up to 3 days per week.'),
        });
        await page.getByTestId('kb-upload-stage').click();
        await expect(page.getByTestId('kb-upload-commit')).toBeEnabled();

        await page.reload();
        await page.getByTestId('admin-widget-tab-setup').click();
        await page.getByTestId(/^widget-setup-resume-/).click();
        await page.getByTestId(/^widget-setup-batch-/).click();
        await page.getByTestId('kb-upload-commit').click();
        await page.getByTestId('kb-upload-done-close').click({ timeout: 35_000 });
        await expect(page.getByTestId('widget-setup-continue-embed')).toBeEnabled();
        await page.getByTestId('widget-setup-continue-embed').click();

        const origin = 'https://staff.example.test';
        await page.getByTestId('widget-setup-mode').selectOption(access === 'authenticated' ? 'fullscreen' : 'inline');
        await page.getByTestId('widget-setup-origins').fill(`${origin}/invalid-path`);
        await page.getByTestId('widget-setup-issue-key').click();
        await expect(page.getByRole('alert')).toContainText(/origin|origine/i);
        await page.getByTestId('widget-setup-origins').fill(origin);
        const issued = page.waitForResponse((response) => /\/api\/admin\/widget-setups\/\d+\/key$/.test(response.url()) && response.status() === 201);
        await page.getByTestId('widget-setup-issue-key').click();
        const result = await (await issued).json();
        expect(result.data.project_key).toBe('employee-documentation');

        if (access === 'authenticated') {
            await expect(page.getByTestId('admin-widget-embed-auth-browser')).toContainText('userTokenUrl');
            await expect(page.getByTestId('admin-widget-embed-auth-browser')).toContainText("mode: 'fullscreen'");
            await expect(page.getByTestId('admin-widget-embed-auth-env')).toContainText('ik_');
            const dialog = page.getByTestId('admin-widget-keys-embed-dialog');
            const close = dialog.getByRole('button', { name: 'Close', exact: true });
            await close.focus();
            await page.keyboard.press('Enter');
            await expect(dialog).toBeHidden();
            await page.getByTestId('widget-setup-open-snippet').click();
            await expect(page.getByTestId('admin-widget-embed-auth-secret-missing')).toBeVisible();
        } else {
            await expect(page.getByTestId('admin-widget-embed-snippet')).toContainText(result.data.public_key);
            await expect(page.getByTestId('admin-widget-embed-snippet')).toContainText("mode: 'inline'");
            const response = await page.request.post('/api/widget/sessions/start', {
                headers: { Origin: origin, 'X-Widget-Key': result.data.public_key, Accept: 'application/json' },
                data: { snapshot: { page: { url: `${origin}/help` } }, message: 'How many days per week may employees work remotely?' },
            });
            expect(response.ok()).toBeTruthy();
            const answer = await response.json();
            expect(answer.citations).toEqual(expect.arrayContaining([expect.objectContaining({ source_path: expect.stringContaining('remote-work.md') })]));
        }
    });
}
