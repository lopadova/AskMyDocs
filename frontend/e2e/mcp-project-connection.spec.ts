import { expect, test } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('amd-ui-locale', 'en'));
    await page.route('**/api/auth/me', (route) => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
    await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
});

test('prepares a distinct plugin connection without requesting secrets', async ({ page }) => {
    await page.route('**/api/oauth/mcp-connections', (route) => route.fulfill({ status: 201, json: {
        id: 'fixture', mcp_url: 'https://askmydocsdev.test/mcp/knowledge/fixture', download_url: '/fixture/plugin.zip',
    } }));
    await page.goto('/mcp/connect');
    await page.getByRole('button', { name: 'Prepare connection', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Download Codex plugin' })).toHaveAttribute('href', '/fixture/plugin.zip');
    await expect(page.getByText('https://askmydocsdev.test/mcp/knowledge/fixture', { exact: true })).toBeVisible();
    await page.getByText('Connect through the Codex CLI', { exact: true }).click();
    await expect(page.getByText("codex mcp login 'askmydocs-fixture'", { exact: true })).toBeVisible();
    await expect(page.getByRole('textbox')).toHaveCount(0);
});

test('shows Claude cloud requirements and client-specific instructions with keyboard selection', async ({ page }) => {
    await page.route('**/api/oauth/mcp-connections', (route) => route.fulfill({ status: 201, json: {
        id: 'fixture', mcp_url: 'https://askmydocsdev.test/mcp/knowledge/fixture', download_url: '/fixture/plugin.zip',
    } }));
    await page.goto('/mcp/connect');
    await page.getByLabel('Application', { exact: true }).focus();
    await page.keyboard.press('ArrowDown');
    await page.getByRole('option', { name: 'Claude web / Desktop', exact: true }).click();
    await expect(page.getByRole('note')).toContainText('public HTTPS URL');
    await page.getByRole('button', { name: 'Prepare connection', exact: true }).click();
    await expect(page.getByText(/Choose Sign in now and Register automatically/)).toBeVisible();
    await page.getByLabel('Application', { exact: true }).click();
    await page.getByRole('option', { name: 'Claude Code', exact: true }).click();
    await expect(page.getByText("claude mcp add --transport http 'askmydocs-fixture' 'https://askmydocsdev.test/mcp/knowledge/fixture'", { exact: true })).toBeVisible();
});

test('chooses one company and resets the project to that company before consent', async ({ page }) => {
    const requests: Record<string, string>[] = [];
    await page.route('**/api/oauth/authorization/fixture', async (route) => {
        if (route.request().method() === 'POST') {
            requests.push(route.request().postDataJSON());
            return route.fulfill({ json: { redirect_to: '/mcp/connect' } });
        }
        return route.fulfill({ json: { client: { name: 'Codex', redirect_uri: 'http://127.0.0.1/callback' },
            scopes: [{ id: 'mcp:read', description: 'Read one project' }], requires_project: true,
            teams: [{ tenant_id: 'acme', name: 'Acme', projects: [{ project_key: 'engineering' }, { project_key: 'legal' }] },
                { tenant_id: 'other', name: 'Other', projects: [{ project_key: 'support' }] }],
            user: { name: 'Fixture', email: 'fixture@example.test' }, token_ttl_days: 90 } });
    });
    await page.goto('/oauth/consent?request=fixture');
    await page.getByLabel('Company', { exact: true }).click();
    await page.getByRole('option', { name: 'Other', exact: true }).click();
    await expect(page.getByLabel('Project', { exact: true })).toContainText('support');
    await page.getByRole('button', { name: 'Connect application' }).click();
    await expect.poll(() => requests.length).toBe(1);
    expect(requests[0]).toMatchObject({ decision: 'approve', tenant_id: 'other', project_key: 'support' });
});

test('shows the import preview and sends approval only after human activation', async ({ page }) => {
    let confirmations = 0;
    const preview = { import_id: 'fixture', tenant_id: 'acme', project_key: 'engineering', status: 'awaiting_approval', ready: false,
        documents: [{ filename: 'spec.md', bytes: 42, sha256: 'fixture', preview: '<script>window.untrusted = true</script>' }],
        items: [{ filename: 'spec.md', status: 'staged', error: null, document_id: null }] };
    await page.route('**/api/oauth/mcp-imports/00000000-0000-4000-8000-000000000001', (route) => route.fulfill({ json: preview }));
    await page.route('**/api/oauth/mcp-imports/00000000-0000-4000-8000-000000000001/confirm', (route) => {
        confirmations++;
        return route.fulfill({ status: 202, json: { ...preview, status: 'processing' } });
    });
    await page.goto('/mcp/import-review/00000000-0000-4000-8000-000000000001');
    await expect(page.getByText('acme / engineering')).toBeVisible();
    await expect(page.getByText('<script>window.untrusted = true</script>', { exact: true })).toBeVisible();
    expect(confirmations).toBe(0);
    await page.getByRole('button', { name: 'Approve and index documents' }).click();
    await expect(page.getByRole('button', { name: 'Refresh status' })).toBeVisible();
    expect(confirmations).toBe(1);
});
