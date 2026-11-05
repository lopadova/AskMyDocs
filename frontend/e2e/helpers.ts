import { expect, type Page, type Locator } from '@playwright/test';

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

/** Open the real portalled shadcn menu, preserving an already open selection. */
export async function openSelect(trigger: Locator): Promise<Locator> {
    if (await trigger.getAttribute('aria-expanded') !== 'true') await trigger.click();
    const menu = trigger.page().getByRole('listbox');
    await expect(menu).toBeVisible();
    return menu;
}

type SelectChoice = string | { value: string } | { label: string | RegExp } | { index: number };

/** Choose through the visible menu; never mutate the hidden form select. */
export async function chooseSelectOption(trigger: Locator, choice: SelectChoice): Promise<void> {
    const menu = await openSelect(trigger);
    const option = typeof choice === 'string' || 'value' in choice
        ? menu.locator(`[role="option"][data-value=${JSON.stringify(typeof choice === 'string' ? choice : choice.value)}]`)
        : 'label' in choice
            ? menu.getByRole('option', { name: choice.label, exact: typeof choice.label === 'string' })
            : menu.getByRole('option').nth(choice.index);
    await option.click();
    await expect(menu).toBeHidden();
}

/**
 * Wait for the chat thread to reach a terminal (non-loading) state.
 * `data-state` is the source of truth — never sleep.
 *
 * Copilot #1 fix: `Locator.evaluate` only accepts (fn, arg?) — not a
 * third `options` object. Using Playwright's native expect matcher on
 * the attribute gives us the same polling semantics with a proper
 * timeout API and no custom MutationObserver plumbing.
 */
export async function waitForThreadReady(page: Page, timeout = 15_000): Promise<void> {
    const t = thread(page);
    await t.waitFor({ state: 'visible', timeout });
    await expect(t).not.toHaveAttribute('data-state', 'loading', { timeout });
}
