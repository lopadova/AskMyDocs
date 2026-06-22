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
/**
 * Probe the ambient localStorage: it counts as usable only if a setItem +
 * removeItem round-trip does NOT throw. Some runtimes expose a setItem function
 * that still throws at call time (no valid backing store / quota), so a bare
 * `typeof setItem === 'function'` check is not enough.
 */
function ambientLocalStorageWorks(): boolean {
    try {
        // Even READING globalThis.localStorage can throw on an opaque origin /
        // disabled-storage runtime, so the access itself is inside the try.
        const ls = globalThis.localStorage as Storage | undefined;
        if (!ls || typeof ls.setItem !== 'function' || typeof ls.removeItem !== 'function') {
            return false;
        }
        const probe = '__ls_probe__';
        ls.setItem(probe, '1');
        ls.removeItem(probe);
        return true;
    } catch {
        return false;
    }
}

if (!ambientLocalStorageWorks()) {
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
    // `defineProperty` throws if the runtime already exposes a non-configurable
    // `localStorage` (some Node/JSDOM combos do). Fall back to a plain assignment,
    // which still installs the polyfill on a non-configurable-but-WRITABLE slot.
    // If even that fails, swallow and let the test that actually needs storage
    // surface the real error rather than crashing the whole setup.
    try {
        Object.defineProperty(globalThis, 'localStorage', { value: memoryStorage, configurable: true });
    } catch {
        try {
            (globalThis as { localStorage: Storage }).localStorage = memoryStorage;
        } catch {
            /* non-configurable, non-writable ambient localStorage — leave it as-is */
        }
    }
}
