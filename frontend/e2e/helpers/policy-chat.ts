import { expect, type Page } from '@playwright/test';
import { chooseSelectOption } from '../helpers';
import { E2E_BASE_URL } from '../setup-helpers';

/** Configure the trusted business scope through the real admin API, without answer facts. */
export async function preparePolicyChat(page: Page): Promise<void> {
    await page.goto('/app/chat');
    const xsrf = (await page.context().cookies(E2E_BASE_URL)).find(cookie => cookie.name === 'XSRF-TOKEN');
    if (!xsrf) throw new Error('Missing CSRF token for the synthetic retrieval profile.');
    const response = await page.request.put('/api/admin/kb/retrieval-profiles', {
        headers: { 'X-Tenant-Id': 'a-demo', 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'application/json' },
        data: { project_key: 'hr-portal', company_context: 'Demo Company HR portal for employee policies and internal guidelines.',
            glossary: [], relevant_entities: [], expected_facts: [], preferred_source_types: [] },
    });
    expect(response.ok(), `Retrieval profile failed: ${response.status()} ${await response.text()}`).toBe(true);
    await chooseSelectOption(page.getByTestId('chat-project-selector'), 'hr-portal');
}
