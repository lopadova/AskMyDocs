import '@testing-library/jest-dom/vitest';

/*
 * jsdom polyfills for Radix UI primitives (Dialog, Tabs, …) used by the
 * shadcn/ui components. jsdom omits these DOM APIs; Radix calls them
 * during focus management and layout measurement. Each is added only
 * when missing, so a future jsdom that ships them wins.
 */
if (typeof Element !== 'undefined') {
    if (!Element.prototype.hasPointerCapture) {
        Element.prototype.hasPointerCapture = () => false;
    }
    if (!Element.prototype.setPointerCapture) {
        Element.prototype.setPointerCapture = () => {};
    }
    if (!Element.prototype.releasePointerCapture) {
        Element.prototype.releasePointerCapture = () => {};
    }
    if (!Element.prototype.scrollIntoView) {
        Element.prototype.scrollIntoView = () => {};
    }
}

if (typeof globalThis.ResizeObserver === 'undefined') {
    globalThis.ResizeObserver = class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    };
}

/*
 * localStorage polyfill — the zustand `persist` middleware behind auth-store /
 * team-store writes to localStorage on setMe()/clear(). jsdom normally provides
 * a working `window.localStorage`, but some local Node runtimes shadow it with
 * an experimental localStorage that lacks a valid backing path ("--localstorage-file
 * was provided without a valid path" → `storage.setItem is not a function`).
 * Install a standards-compliant in-memory Storage ONLY when the ambient one is
 * missing or broken, so CI (where localStorage works) is a no-op and local runs
 * are deterministic.
 */
if (typeof globalThis.localStorage === 'undefined' || typeof globalThis.localStorage.setItem !== 'function') {
    const store = new Map<string, string>();
    const memoryStorage: Storage = {
        get length(): number {
            return store.size;
        },
        clear: (): void => {
            store.clear();
        },
        getItem: (key: string): string | null => (store.has(key) ? store.get(key)! : null),
        key: (index: number): string | null => Array.from(store.keys())[index] ?? null,
        removeItem: (key: string): void => {
            store.delete(key);
        },
        setItem: (key: string, value: string): void => {
            store.set(key, String(value));
        },
    };
    Object.defineProperty(globalThis, 'localStorage', { value: memoryStorage, configurable: true });
}
