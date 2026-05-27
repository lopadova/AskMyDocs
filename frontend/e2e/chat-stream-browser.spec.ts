import { expect, type Page } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';

/*
 * v8.5 — the DEFINITIVE browser streaming E2E.
 *
 * This is the test that would have caught the v8.4 chat crashes (source-url
 * providerMetadata + finish.usage). It drives the REAL `/messages/stream` SSE
 * through the REAL `@ai-sdk` transport in the browser — the only layer that
 * validates each UIMessageChunk against the SDK zod schema, which is exactly
 * where those wire-format bugs fired ("Type validation failed …").
 *
 * Determinism without a live LLM: the Playwright webServer runs with
 * AI_PROVIDER=fake / AI_EMBEDDINGS_PROVIDER=fake (see playwright.config.ts).
 * FakeProvider streams a canned answer and returns a constant embedding
 * vector, so a doc ingested into `hr-portal` is ALWAYS retrieved → the
 * controller ALWAYS emits a real `source-url` citation frame. The fresh
 * `/app/chat` turn scopes to PROJECTS[0] = `hr-portal` (ChatView), so the
 * ingested doc is in scope.
 *
 * NOTHING is stubbed here (R13): no `page.route` on internal routes. The whole
 * round-trip — auth, retrieval, the real SSE frames, the real SDK transport,
 * the React render — runs for real. A wire-format regression makes the assertions
 * below fail (the stream never reaches `ready` / no chip / a pageerror), instead
 * of crashing at the user's first click.
 */

test.describe.configure({ timeout: 60_000 });

async function ingestHrPortalDoc(page: Page): Promise<void> {
    await page.request.get('/sanctum/csrf-cookie');
    const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrf) {
        throw new Error('XSRF-TOKEN cookie missing after /sanctum/csrf-cookie');
    }
    const res = await page.request.post('/api/kb/ingest', {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'application/json' },
        data: {
            documents: [
                {
                    project_key: 'hr-portal',
                    source_path: 'policies/e2e-remote-work.md',
                    title: 'Remote Work Policy',
                    mime_type: 'text/markdown',
                    content: '# Remote Work Policy\n\nEmployees may work remotely up to 3 days per week with manager approval.',
                },
            ],
        },
    });
    if (!res.ok()) {
        throw new Error(`ingest failed: ${res.status()} ${await res.text()}`);
    }
}

test.describe('Chat streaming — real SSE through the real @ai-sdk transport', () => {
    test('a grounded chat turn streams text + a citation chip + completes, with NO SDK validation error', async ({ page }) => {
        // Capture the exact failure mode of the v8.4 bugs: the SDK transport
        // throws "Type validation failed" on a bad frame, surfacing as a
        // pageerror / console error.
        const fatalErrors: string[] = [];
        page.on('pageerror', (e) => fatalErrors.push(`pageerror: ${e.message}`));
        page.on('console', (m) => {
            if (m.type() === 'error' && /type validation failed|invalid_union|unrecognized_keys/i.test(m.text())) {
                fatalErrors.push(`console.error: ${m.text()}`);
            }
        });

        // Queue is sync (testing env) so the ingest job runs inline; the
        // fake embeddings make this doc retrievable for any hr-portal query.
        await ingestHrPortalDoc(page);

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('How many days per week can I work remotely?');
        await send.click();

        // The assistant answer streams in (text-* frames parsed by the SDK).
        const assistant = page.locator('[data-testid^="chat-message-"][data-role="assistant"]').last();
        await expect(assistant).toBeVisible({ timeout: 30_000 });
        await expect
            .poll(async () => (await assistant.innerText()).trim().length, { timeout: 30_000 })
            .toBeGreaterThan(0);

        // The citation chip rendered → the real `source-url` frame parsed
        // cleanly through the SDK (the v8.4 crash #1 would have aborted here).
        await expect(page.getByTestId('chat-citations')).toBeVisible({ timeout: 30_000 });
        await expect(page.getByTestId('chat-citation-0')).toBeVisible();

        // The stream reached its terminal state → the `finish` frame parsed
        // cleanly (the v8.4 crash #2 would have aborted before ready).
        await waitForThreadReady(page, 30_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');

        // No SDK validation error fired at any point.
        expect(fatalErrors, fatalErrors.join('\n')).toEqual([]);
    });
});
