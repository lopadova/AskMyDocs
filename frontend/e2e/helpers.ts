import type { Page, Locator } from '@playwright/test';

/*
 * Small testid-based helpers for E2E scenarios. Every helper is a thin
 * wrapper over `page.getByTestId(...)` so tests read like prose while
 * refactors of the underlying DOM structure stay cheap.
 */

export function composer(page: Page): { form: Locator; input: Locator; send: Locator; voice: Locator } {
    return {
        form: page.getByTestId('chat-composer'),
        input: page.getByTestId('chat-composer-input'),
        send: page.getByTestId('chat-composer-send'),
        voice: page.getByTestId('chat-composer-voice'),
    };
}

export function thread(page: Page): Locator {
    return page.getByTestId('chat-thread');
}

export function sidebar(page: Page): Locator {
    return page.getByTestId('chat-sidebar');
}

export function newConversationButton(page: Page): Locator {
    return page.getByTestId('chat-new-conversation');
}

/**
 * Wait for the chat thread to reach a terminal (non-loading) state.
 * `data-state` is the source of truth — never sleep.
 */
export async function waitForThreadReady(page: Page, timeout = 15_000): Promise<void> {
    const t = thread(page);
    await t.waitFor({ state: 'visible', timeout });
    // Wait until the thread is no longer loading.
    await t.evaluate(
        (el) =>
            new Promise<void>((resolve) => {
                const done = () => {
                    const s = el.getAttribute('data-state');
                    return s !== null && s !== 'loading';
                };
                if (done()) {
                    resolve();
                    return;
                }
                const obs = new MutationObserver(() => {
                    if (done()) {
                        obs.disconnect();
                        resolve();
                    }
                });
                obs.observe(el, { attributes: true, attributeFilter: ['data-state'] });
            }),
        undefined,
        { timeout },
    );
}
