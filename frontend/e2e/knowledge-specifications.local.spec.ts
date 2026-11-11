import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

// Optional local Herd acceptance profile. Never calls /testing/reset or migrate:fresh.
const fixturePath = process.env.KNOWLEDGE_E2E_FIXTURE;
test.skip(!fixturePath, 'Set KNOWLEDGE_E2E_FIXTURE to an isolated local fixture.');
const fixture = fixturePath ? JSON.parse(readFileSync(fixturePath, 'utf8')) as { tenant: string; email: string; password: string; team_hash: string; document_ids: number[] } : null;

test.beforeEach(async ({ page, context }) => {
    await page.goto('/');
    await page.evaluate(async () => { await fetch('/sanctum/csrf-cookie', { credentials: 'include' }); });
    const xsrf = (await context.cookies()).find(c => c.name === 'XSRF-TOKEN');
    const status = await page.evaluate(async ({ email, password, csrf }) => {
        const response = await fetch('/api/auth/login', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ email, password }) });
        return response.status;
    }, { email: fixture!.email, password: fixture!.password, csrf: decodeURIComponent(xsrf!.value) });
    expect(status).toBe(200);
});

test('edits, saves and activates a project revision without reprocessing originals', async ({ page }) => {
    await page.goto(`/app/${fixture!.team_hash}/admin/kb/autowiki-settings`);
    const editor = page.getByTestId('knowledge-specification-editor');
    await expect(editor).toBeVisible();
    await editor.getByRole('combobox', { name: 'Specification scope' }).click();
    await page.getByRole('option', { name: 'orders', exact: true }).click();
    const description = editor.getByLabel('Description in Italian');
    await expect(description).toBeVisible();
    await description.fill('Organizzare gli ordini e conservare cronologia e fonti originali. '+Date.now());
    await expect(editor.getByRole('button', { name: 'Activate revision', exact: true })).toBeDisabled();
    await editor.getByRole('button', { name: 'Save new revision', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(editor.getByRole('button', { name: 'Activate revision', exact: true })).toBeEnabled();
    const activate = page.waitForResponse(r => r.url().includes('/activate') && r.request().method() === 'POST');
    await editor.getByRole('button', { name: 'Activate revision', exact: true }).click();
    expect((await activate).status()).toBe(200);
    await expect(editor.getByText(/Active revision:/)).toBeVisible();
    await page.screenshot({ path: '/tmp/knowledge-editor-dark.png', fullPage: true, animations: 'disabled' });
    if (await page.locator('html').getAttribute('data-theme') !== 'light') await page.getByRole('button', { name: 'Toggle theme', exact: true }).click();
    await page.screenshot({ path: '/tmp/knowledge-editor-light.png', fullPage: true, animations: 'disabled' });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(editor.getByRole('button', { name: 'Save new revision', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.screenshot({ path: '/tmp/knowledge-editor-mobile.png', fullPage: true, animations: 'disabled' });
});

test('finds an exact order and reads original timeline and source links', async ({ page }) => {
    await page.goto(`/app/${fixture!.team_hash}/knowledge`);
    await page.getByTestId('kb-browse-project').click();
    await page.getByRole('option', { name: 'orders', exact: true }).click();
    await page.getByRole('button', { name: 'Explore knowledge entities', exact: true }).click();
    const panel = page.getByTestId('knowledge-entities-panel');
    await panel.getByLabel('Identifier or knowledge query').fill('00123');
    await panel.getByRole('button', { name: 'Search entities', exact: true }).click();
    await panel.getByRole('button', { name: 'order · order · 00123' }).click();
    await expect(panel.getByText('Ordine 00123 spedito con corriere.', { exact: true })).toBeVisible();
    await expect(panel.getByText('Ordine 00123 consegnato e ricevuto dal cliente.', { exact: true })).toBeVisible();
    await expect(panel.getByText(/Needs reprocessing/)).toBeVisible();
    await page.screenshot({ path: '/tmp/knowledge-entities-dark.png', fullPage: true, animations: 'disabled' });
    if (await page.locator('html').getAttribute('data-theme') !== 'light') await page.getByRole('button', { name: 'Toggle theme', exact: true }).click();
    await page.screenshot({ path: '/tmp/knowledge-entities-light.png', fullPage: true, animations: 'disabled' });
    await page.setViewportSize({ width: 390, height: 844 });
    // The mobile reader opens its detail pane after a document selection.
    await panel.getByRole('button', { name: /Original email 1/ }).click();
    await expect(page.getByTestId('kb-browse-detail-title')).toHaveText('Original email 1');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
});

test('removes deleted original passages from authorized entity context immediately', async ({ page, context }) => {
    const xsrf = (await context.cookies()).find(c => c.name === 'XSRF-TOKEN');
    const result = await page.evaluate(async ({ tenant, csrf }) => {
        const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Tenant-Id': tenant, 'X-XSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' };
        const deletion = await fetch('/api/kb/documents', { method: 'DELETE', credentials: 'include', headers, body: JSON.stringify({ documents: [{ project_key: 'orders', source_path: tenant+'/email-0.txt' }], force: false }) });
        const search = await fetch('/api/kb/knowledge/entities?project_key=orders&q=00123', { credentials: 'include', headers });
        const data = await search.json();
        const entity = data.entities[0];
        const read = await fetch('/api/kb/knowledge/entities/'+entity.id+'?project_key=orders', { credentials: 'include', headers });
        return { status: deletion.status, context: await read.json() };
    }, { tenant: fixture!.tenant, csrf: decodeURIComponent(xsrf!.value) });
    expect(result.status).toBe(200);
    expect(result.context.timeline).toHaveLength(1);
    expect(result.context.timeline[0].quote).toBe('Ordine 00123 consegnato e ricevuto dal cliente.');
});
